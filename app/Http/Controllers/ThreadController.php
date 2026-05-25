<?php

namespace App\Http\Controllers;

use App\Models\HookCategory;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\RedditSource;
use App\Models\RssFeed;
use App\Models\SocialAccount;
use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use App\Models\WpSource;
use App\Models\YtSource;
use App\Services\AiAssistService;
use App\Services\ThreadBoostService;
use App\Services\ThreadContentGenerationService;
use App\Services\ThreadPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ThreadController extends Controller
{
    private const THREAD_PLATFORM_SLUGS = ['twitter', 'threads', 'bluesky'];

    private const COMPILED_PLATFORM_SLUGS = ['facebook', 'telegram', 'instagram'];

    /**
     * Display a list of threads.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Thread::query()
            ->with(['segments', 'socialAccounts.platform', 'user'])
            ->withCount('segments');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $threads = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('threads.index', compact('threads'));
    }

    /**
     * Show the thread creation form.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();
        $personas = Persona::where('is_active', true)->orderBy('name')->get();
        $hookCategories = HookCategory::active()->ordered()->withCount('activeHooks')->get();

        $sourceTypeCounts = [];
        if ($user->isAdmin()) {
            $sourceTypeCounts = [
                'wordpress' => WpSource::where('is_active', true)->count(),
                'rss' => RssFeed::where('is_active', true)->count(),
                'youtube' => YtSource::where('is_active', true)->count(),
                'reddit' => RedditSource::where('is_active', true)->count(),
            ];
        }

        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();

        // Fils publiés pouvant servir de cible de boost (segment 0 publié quelque part).
        // socialAccounts est chargé pour permettre au composant de filtrer côté JS
        // selon les comptes cochés dans le formulaire.
        $boostableThreads = Thread::query()
            ->where('status', 'published')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('segments.segmentPlatforms', fn ($q) => $q->where('status', 'published')->whereNotNull('platform_url'))
            ->with(['segments' => fn ($q) => $q->orderBy('position')->limit(1), 'socialAccounts:id'])
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        return view('threads.create', compact('accounts', 'platforms', 'personas', 'hookCategories', 'sourceTypeCounts', 'accountGroups', 'boostableThreads'));
    }

    /**
     * Store a new thread with its segments.
     */
    public function store(Request $request, ThreadBoostService $boostService): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'source_type' => 'nullable|string|in:manual,rss,wordpress,youtube,reddit',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'segments' => 'required|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media_json' => 'nullable|string',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'publish_now' => 'nullable|boolean',
            'instagram_compiled_fr' => 'nullable|string|max:2200',
            'boost' => 'nullable|array',
            'boost.source_thread_id' => 'required_with:boost|integer|exists:threads,id',
            'boost.promo_text' => 'required_with:boost|string|max:5000',
        ]);

        $publishNow = $request->boolean('publish_now');
        $user = $request->user();

        $thread = DB::transaction(function () use ($validated, $user, $publishNow, $boostService) {
            $igCompiledFr = isset($validated['instagram_compiled_fr']) ? trim((string) $validated['instagram_compiled_fr']) : '';
            $instagramCompiled = $igCompiledFr !== '' ? ['fr' => $igCompiledFr] : null;

            $thread = Thread::create([
                'user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'source_type' => $validated['source_type'] ?? 'manual',
                'status' => $publishNow ? 'draft' : ($validated['status'] ?? 'draft'),
                'scheduled_at' => $publishNow ? null : ($validated['scheduled_at'] ?? null),
                'instagram_compiled_content' => $instagramCompiled,
            ]);

            // Create segments.
            foreach ($validated['segments'] as $index => $segmentData) {
                $platformContents = $segmentData['platform_contents'] ?? [];
                $platformContents = array_filter($platformContents, fn ($v) => ! empty($v));

                $media = null;
                if (! empty($segmentData['media_json'])) {
                    $media = json_decode($segmentData['media_json'], true);
                }

                $segment = ThreadSegment::create([
                    'thread_id' => $thread->id,
                    'position' => $index + 1,
                    'content_fr' => $segmentData['content_fr'],
                    'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                    'media' => ! empty($media) ? $media : null,
                ]);

                // Create segment platform entries for each account.
                foreach ($validated['accounts'] as $accountId) {
                    $account = SocialAccount::with('platform')->find($accountId);
                    if ($account) {
                        ThreadSegmentPlatform::create([
                            'thread_segment_id' => $segment->id,
                            'social_account_id' => $account->id,
                            'platform_id' => $account->platform_id,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            // Create thread <-> social account pivot entries.
            foreach ($validated['accounts'] as $accountId) {
                $account = SocialAccount::with('platform')->find($accountId);
                if ($account) {
                    $publishMode = in_array($account->platform->slug, self::THREAD_PLATFORM_SLUGS)
                        ? 'thread'
                        : 'compiled';

                    $thread->socialAccounts()->attach($account->id, [
                        'platform_id' => $account->platform_id,
                        'publish_mode' => $publishMode,
                        'status' => 'pending',
                    ]);
                }
            }

            // Insertion d'un segment de boost au milieu du fil si demandé.
            if (! empty($validated['boost'])) {
                $accountModels = SocialAccount::with('platform')->whereIn('id', $validated['accounts'])->get();
                $boostService->insertBoostSegment(
                    $thread,
                    $accountModels,
                    (int) $validated['boost']['source_thread_id'],
                    $validated['boost']['promo_text'],
                );
            }

            return $thread;
        });

        if ($publishNow && $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'thread_id' => $thread->id,
                'show_url' => route('threads.show', $thread),
                'accounts' => $thread->socialAccounts->map(fn ($account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'platform_slug' => $account->platform->slug,
                    'publish_mode' => $account->pivot->publish_mode,
                    'publish_url' => route('threads.publishOne', [$thread, $account]),
                ]),
            ]);
        }

        return redirect()->route('threads.show', $thread)
            ->with('success', 'Fil de discussion créé.');
    }

    /**
     * Display thread details with segments and statuses.
     */
    public function show(Request $request, Thread $thread): View
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403);
        }

        $thread->load([
            'segments.segmentPlatforms.socialAccount.platform',
            'socialAccounts.platform',
            'user',
        ]);

        $attachedIds = $thread->socialAccounts->pluck('id')->all();

        $availableAccounts = $user->activeSocialAccounts()
            ->with('platform')
            ->whereNotIn('social_accounts.id', $attachedIds)
            ->orderBy('name')
            ->get();

        return view('threads.show', compact('thread', 'availableAccounts'));
    }

    /**
     * Show the edit form for a thread.
     */
    public function edit(Request $request, Thread $thread): View
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403);
        }

        if (in_array($thread->status, ['publishing', 'published']) || $thread->hasPublishedSegments()) {
            return redirect()->route('threads.show', $thread)
                ->with('error', 'Ce fil contient déjà des publications. Pour le modifier, cliquez d\'abord « Reset » sur chaque compte concerné (les posts existants sur les plateformes devront être supprimés manuellement).');
        }

        $thread->load(['segments', 'socialAccounts.platform']);

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();
        $personas = Persona::where('is_active', true)->orderBy('name')->get();
        $selectedAccountIds = $thread->socialAccounts->pluck('id')->toArray();
        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();

        $boostableThreads = Thread::query()
            ->where('status', 'published')
            ->where('id', '!=', $thread->id)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('segments.segmentPlatforms', fn ($q) => $q->where('status', 'published')->whereNotNull('platform_url'))
            ->with(['segments' => fn ($q) => $q->orderBy('position')->limit(1), 'socialAccounts:id'])
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        return view('threads.edit', compact('thread', 'accounts', 'platforms', 'personas', 'selectedAccountIds', 'accountGroups', 'boostableThreads'));
    }

    /**
     * Update a thread and its segments.
     */
    public function update(Request $request, Thread $thread, ThreadBoostService $boostService): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403);
        }

        if (in_array($thread->status, ['publishing', 'published']) || $thread->hasPublishedSegments()) {
            return redirect()->route('threads.show', $thread)
                ->with('error', 'Ce fil contient déjà des publications. Pour le modifier, cliquez d\'abord « Reset » sur chaque compte concerné.');
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:2048',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'segments' => 'required|array|min:1',
            'segments.*.content_fr' => 'required|string|max:10000',
            'segments.*.platform_contents' => 'nullable|array',
            'segments.*.platform_contents.*' => 'nullable|string|max:10000',
            'segments.*.media_json' => 'nullable|string',
            'status' => 'required|in:draft,scheduled',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'instagram_compiled_fr' => 'nullable|string|max:2200',
            'boost' => 'nullable|array',
            'boost.source_thread_id' => 'required_with:boost|integer|exists:threads,id',
            'boost.promo_text' => 'required_with:boost|string|max:5000',
        ]);

        DB::transaction(function () use ($thread, $validated, $boostService) {
            $igCompiled = $thread->instagram_compiled_content ?? [];
            $igCompiledFr = isset($validated['instagram_compiled_fr']) ? trim((string) $validated['instagram_compiled_fr']) : '';
            if ($igCompiledFr !== '') {
                $igCompiled['fr'] = $igCompiledFr;
            } else {
                unset($igCompiled['fr']);
            }

            $thread->update([
                'title' => $validated['title'] ?? null,
                'source_url' => $validated['source_url'] ?? null,
                'status' => $validated['status'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'instagram_compiled_content' => ! empty($igCompiled) ? $igCompiled : null,
            ]);

            // Delete old segments (cascade deletes segment platforms).
            $thread->segments()->delete();

            // Detach old accounts.
            $thread->socialAccounts()->detach();

            // Recreate segments and pivots.
            foreach ($validated['segments'] as $index => $segmentData) {
                $platformContents = $segmentData['platform_contents'] ?? [];
                $platformContents = array_filter($platformContents, fn ($v) => ! empty($v));

                $media = null;
                if (! empty($segmentData['media_json'])) {
                    $media = json_decode($segmentData['media_json'], true);
                }

                $segment = ThreadSegment::create([
                    'thread_id' => $thread->id,
                    'position' => $index + 1,
                    'content_fr' => $segmentData['content_fr'],
                    'platform_contents' => ! empty($platformContents) ? $platformContents : null,
                    'media' => ! empty($media) ? $media : null,
                ]);

                foreach ($validated['accounts'] as $accountId) {
                    $account = SocialAccount::with('platform')->find($accountId);
                    if ($account) {
                        ThreadSegmentPlatform::create([
                            'thread_segment_id' => $segment->id,
                            'social_account_id' => $account->id,
                            'platform_id' => $account->platform_id,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            foreach ($validated['accounts'] as $accountId) {
                $account = SocialAccount::with('platform')->find($accountId);
                if ($account) {
                    $publishMode = in_array($account->platform->slug, self::THREAD_PLATFORM_SLUGS)
                        ? 'thread'
                        : 'compiled';

                    $thread->socialAccounts()->attach($account->id, [
                        'platform_id' => $account->platform_id,
                        'publish_mode' => $publishMode,
                        'status' => 'pending',
                    ]);
                }
            }

            if (! empty($validated['boost'])) {
                $accountModels = SocialAccount::with('platform')->whereIn('id', $validated['accounts'])->get();
                $boostService->insertBoostSegment(
                    $thread,
                    $accountModels,
                    (int) $validated['boost']['source_thread_id'],
                    $validated['boost']['promo_text'],
                );
            }
        });

        return redirect()->route('threads.show', $thread)
            ->with('success', 'Fil de discussion mis à jour.');
    }

    /**
     * Delete a thread.
     */
    public function destroy(Request $request, Thread $thread): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403);
        }

        $thread->delete();

        return redirect()->route('threads.index')
            ->with('success', 'Fil de discussion supprimé.');
    }

    /**
     * AJAX: Generate thread segments from a URL via AI.
     */
    public function generateFromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => 'required|url|max:2048',
            'context_instructions' => 'nullable|string|max:1000',
            'persona_id' => 'required|exists:personas,id',
            'hook_category_id' => 'nullable|integer|exists:hook_categories,id',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'folder' => 'nullable|string|exists:media_folders,slug',
        ]);

        // Determine platform slugs from selected accounts.
        $platformSlugs = SocialAccount::with('platform')
            ->whereIn('id', $validated['accounts'])
            ->get()
            ->pluck('platform.slug')
            ->unique()
            ->values()
            ->toArray();

        $persona = Persona::findOrFail($validated['persona_id']);
        $service = new ThreadContentGenerationService;

        $hookCategoryId = $validated['hook_category_id'] ?? null;
        $contextInstructions = $validated['context_instructions'] ?? null;
        $folderSlug = $validated['folder'] ?? null;
        $result = $service->generate($validated['source_url'], $persona, $platformSlugs, $hookCategoryId, $contextInstructions, $folderSlug, $validated['accounts']);

        if (! $result) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de générer le fil de discussion. Vérifiez l\'URL et réessayez.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * AJAX: Compile all segments into a single Instagram caption via AI.
     */
    public function compileInstagram(Request $request, Thread $thread, AiAssistService $ai): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'lang' => 'nullable|string|in:fr,en,pt,es,de,it',
        ]);
        $lang = $validated['lang'] ?? 'fr';

        $compiled = $ai->compileForInstagram($thread, $lang);

        if ($compiled === null) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de generer le texte Instagram. Verifie la cle OpenAI et reessaie.',
            ], 422);
        }

        $current = $thread->instagram_compiled_content ?? [];
        $current[$lang] = $compiled;
        $thread->update(['instagram_compiled_content' => $current]);

        return response()->json([
            'success' => true,
            'data' => [
                'lang' => $lang,
                'content' => $compiled,
                'length' => mb_strlen($compiled),
            ],
        ]);
    }

    /**
     * AJAX: Regenerate a single segment.
     */
    public function regenerateSegment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_url' => 'required|url|max:2048',
            'persona_id' => 'required|exists:personas,id',
            'position' => 'required|integer|min:1',
            'total_segments' => 'required|integer|min:1',
            'previous_content' => 'nullable|string',
            'next_content' => 'nullable|string',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $platformSlugs = SocialAccount::with('platform')
            ->whereIn('id', $validated['accounts'])
            ->get()
            ->pluck('platform.slug')
            ->unique()
            ->values()
            ->toArray();

        $persona = Persona::findOrFail($validated['persona_id']);
        $service = new ThreadContentGenerationService;

        $result = $service->regenerateSegment(
            $validated['source_url'],
            $persona,
            $validated['position'],
            $validated['total_segments'],
            $validated['previous_content'] ?? '',
            $validated['next_content'] ?? '',
            $platformSlugs,
        );

        if (! $result) {
            return response()->json([
                'success' => false,
                'error' => 'Impossible de régénérer le segment.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * AJAX: Publish a thread to all linked accounts.
     */
    public function publishAll(Request $request, Thread $thread): JsonResponse
    {
        // Threads requires 35s between segments — extend timeout accordingly.
        set_time_limit(600);

        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = app(ThreadPublishingService::class);
        $results = $service->publishAll($thread);

        $thread->refresh();

        return response()->json([
            'success' => in_array($thread->status, ['published', 'partial']),
            'status' => $thread->status,
            'results' => $results,
        ]);
    }

    /**
     * AJAX: Publish a thread to a single account.
     */
    public function publishOne(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        // Threads requires 35s between segments — extend timeout accordingly.
        set_time_limit(600);

        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = app(ThreadPublishingService::class);
        $results = $service->publishToAccount($thread, $socialAccount);

        $thread->refresh();

        $allSuccess = collect($results)->every(fn ($r) => $r['success'] ?? false);

        return response()->json([
            'success' => $allSuccess,
            'account' => $socialAccount->name,
            'results' => $results,
        ]);
    }

    /**
     * AJAX: Reset a thread's account status for retry.
     */
    public function resetAccount(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = app(ThreadPublishingService::class);
        $service->resetAccount($thread, $socialAccount);

        if (in_array($thread->status, ['published', 'failed', 'partial'])) {
            $thread->update(['status' => 'draft', 'published_at' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Remis en attente.']);
    }

    /**
     * AJAX: Ajoute un compte social a un fil deja cree (eventuellement deja
     * publie sur d'autres comptes). Le compte est attache en pending, avec un
     * thread_segment_platform par segment. Les publications existantes sur les
     * autres comptes ne sont pas touchees — le tracking reste unifie.
     */
    public function addAccount(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        // Le compte doit etre lie au user (pivot social_account_user actif).
        $isLinked = $socialAccount->users()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $user->isAdmin() && ! $isLinked) {
            return response()->json(['success' => false, 'error' => 'Ce compte ne vous est pas accessible.'], 403);
        }

        // Pas de doublon.
        $alreadyAttached = $thread->socialAccounts()
            ->where('social_account_id', $socialAccount->id)
            ->exists();

        if ($alreadyAttached) {
            return response()->json(['success' => false, 'error' => 'Ce compte est déjà sur ce fil.'], 422);
        }

        $socialAccount->loadMissing('platform');
        $publishMode = in_array($socialAccount->platform->slug, self::THREAD_PLATFORM_SLUGS)
            ? 'thread'
            : 'compiled';

        $thread->loadMissing('segments');

        DB::transaction(function () use ($thread, $socialAccount, $publishMode) {
            app(ThreadPublishingService::class)->addAccount($thread, $socialAccount, $publishMode);
        });

        return response()->json([
            'success' => true,
            'message' => 'Compte ajouté au fil.',
            'publish_mode' => $publishMode,
        ]);
    }

    /**
     * AJAX: Retire completement un compte d'un fil (detach + suppression des
     * thread_segment_platform). Les posts deja publies cote plateforme ne sont
     * pas supprimes — c'est a l'utilisateur de le faire si necessaire.
     */
    public function removeAccount(Request $request, Thread $thread, SocialAccount $socialAccount): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $thread->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $service = app(ThreadPublishingService::class);
        $service->removeAccount($thread, $socialAccount);

        return response()->json(['success' => true, 'message' => 'Compte retiré du fil.']);
    }
}
