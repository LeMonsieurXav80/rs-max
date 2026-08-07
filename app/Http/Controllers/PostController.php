<?php

namespace App\Http\Controllers;

use App\Models\Hashtag;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\PartnerTagService;
use App\Services\Stats\PostBenchmarkService;
use App\Services\Stats\StatsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PostController extends Controller
{
    /**
     * Compare un brouillon à l'historique publié de chaque compte sélectionné.
     *
     * Répond toujours 200 avec un tableau (éventuellement vide) : c'est un
     * indicateur d'aide à la rédaction, il ne doit jamais bloquer le composer.
     */
    public function benchmark(Request $request, PostBenchmarkService $benchmark): JsonResponse
    {
        $validated = $request->validate([
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'content_fr' => 'nullable|string',
            'link_url' => 'nullable|string',
            'has_media' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date',
        ]);

        // Seuls les comptes réellement accessibles à l'utilisateur.
        $accounts = $request->user()->activeSocialAccounts()
            ->whereIn('social_accounts.id', $validated['accounts'])
            ->get();

        $scheduledAt = ! empty($validated['scheduled_at'])
            ? \Illuminate\Support\Carbon::parse($validated['scheduled_at'])
            : null;

        $draft = [
            'length' => mb_strlen((string) ($validated['content_fr'] ?? '')),
            'has_media' => (bool) ($validated['has_media'] ?? false),
            'has_link' => ! empty($validated['link_url']),
            'hour' => $scheduledAt ? (int) $scheduledAt->format('G') : (int) now()->format('G'),
        ];

        $results = $accounts
            ->map(fn (SocialAccount $account) => $benchmark->forDraft($account, $draft))
            ->values();

        return response()->json(['accounts' => $results]);
    }

    /**
     * Save the user's default account selection for post creation.
     */
    public function saveDefaultAccounts(Request $request)
    {
        $validated = $request->validate([
            'accounts' => 'required|array',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $request->user()->update(['default_accounts' => $validated['accounts']]);

        return response()->json(['success' => true]);
    }

    /**
     * Display a paginated list of posts, optionally filtered by status.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $userId = $user->id;

        // Only load postPlatforms whose social account is active FOR THIS USER
        $activePostPlatforms = fn ($q) => $q->whereHas('socialAccount', function ($sq) use ($userId) {
            $sq->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
        });

        // Only show posts that have at least one active account for this user
        $hasActiveAccount = fn ($q) => $q->whereHas('postPlatforms', function ($ppq) use ($userId) {
            $ppq->whereHas('socialAccount', function ($sq) use ($userId) {
                $sq->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
            });
        });

        $query = Post::query()->with([
            'postPlatforms' => $activePostPlatforms,
            'postPlatforms.platform',
            'postPlatforms.socialAccount',
            'user',
            'wpPost.wpItem',
            'ytPost.ytItem',
            'rssPost.rssItem',
            'redditPost.redditItem',
        ]);

        // Admin sees all posts, regular user sees only own posts
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        // Only show posts with at least one active account for this user
        $hasActiveAccount($query);

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by media type if provided
        $applyMediaTypeFilter = function ($q) use ($request) {
            if (! $request->filled('media_type')) {
                return;
            }
            match ($request->input('media_type')) {
                'photo' => $q->whereNotNull('media')
                    ->whereRaw('JSON_LENGTH(media) = 1')
                    ->whereRaw("JSON_EXTRACT(media, '$[0].mimetype') LIKE 'image/%'"),
                'video' => $q->whereNotNull('media')
                    ->whereRaw('JSON_LENGTH(media) = 1')
                    ->whereRaw("JSON_EXTRACT(media, '$[0].mimetype') LIKE 'video/%'"),
                'carousel' => $q->whereNotNull('media')
                    ->whereRaw('JSON_LENGTH(media) > 1'),
                default => null,
            };
        };
        $applyMediaTypeFilter($query);

        // Groupes et comptes pour les filtres (mêmes données que le formulaire de création)
        $accountGroups = $user->accountGroups()->with('socialAccounts:id')->orderBy('sort_order')->get();
        $accounts = $user->activeSocialAccounts()
            ->with('platform')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        // Filtre par groupe (au moins un compte du groupe) ou par compte individuel
        $applyAccountFilter = function ($q) use ($request, $accountGroups) {
            if ($request->filled('group_id')) {
                $group = $accountGroups->firstWhere('id', (int) $request->input('group_id'));
                $accountIds = $group ? $group->socialAccounts->pluck('id')->all() : [];
                $q->whereHas('postPlatforms', fn ($ppq) => $ppq->whereIn('social_account_id', $accountIds));
            }

            if ($request->filled('account_id')) {
                $q->whereHas('postPlatforms', fn ($ppq) => $ppq->where('social_account_id', (int) $request->input('account_id')));
            }
        };
        $applyAccountFilter($query);

        // List view: paginated (scheduled posts sorted by next to publish first)
        $listQuery = clone $query;
        if ($request->input('status') === 'scheduled') {
            $listQuery->orderBy('scheduled_at');
        } else {
            $listQuery->orderByRaw('COALESCE(published_at, scheduled_at, created_at) DESC');
        }
        $posts = $listQuery->paginate(15)->withQueryString();

        // Calendar view: posts for the selected month
        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $calendarQuery = Post::query()->with([
            'postPlatforms' => $activePostPlatforms,
            'postPlatforms.platform',
            'postPlatforms.socialAccount',
            'user',
            'wpPost.wpItem',
            'ytPost.ytItem',
            'rssPost.rssItem',
            'redditPost.redditItem',
        ]);
        if (! $user->isAdmin()) {
            $calendarQuery->where('user_id', $user->id);
        }
        $hasActiveAccount($calendarQuery);
        if ($request->filled('status')) {
            $calendarQuery->where('status', $request->input('status'));
        }
        $applyMediaTypeFilter($calendarQuery);
        $applyAccountFilter($calendarQuery);

        $calendarPosts = $calendarQuery
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('scheduled_at', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('published_at', [$startOfMonth, $endOfMonth]);
            })
            ->orderByRaw('COALESCE(scheduled_at, published_at) ASC')
            ->get()
            ->groupBy(fn (Post $p) => ($p->scheduled_at ?? $p->published_at)->format('Y-m-d'));

        $prevMonth = $startOfMonth->copy()->subMonth()->format('Y-m');
        $nextMonth = $startOfMonth->copy()->addMonth()->format('Y-m');

        return view('posts.index', compact(
            'posts', 'calendarPosts', 'startOfMonth', 'endOfMonth', 'month', 'prevMonth', 'nextMonth',
            'accountGroups', 'accounts'
        ));
    }

    /**
     * Show the form for creating a new post.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        // Both admin and regular user see their own active accounts (per-user is_active)
        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();
        $defaultAccountIds = $user->default_accounts ?? [];
        $charLimits = $this->getPlatformCharLimits();
        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();
        $partnerOptions = app(PartnerTagService::class)->options();

        return view('posts.create', compact('accounts', 'platforms', 'defaultAccountIds', 'charLimits', 'accountGroups', 'partnerOptions'));
    }

    /**
     * Validate and store a newly created post.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            // 30 000 : les posts longs X Premium montent à 25 000 caractères,
            // et un Article davantage encore.
            'content_fr' => 'required|string|max:30000',
            'content_en' => 'nullable|string|max:30000',
            'article_title' => 'nullable|string|max:255',
            'platform_contents' => 'nullable|array',
            'platform_contents.*' => 'nullable|string|max:30000',
            'hashtags' => 'nullable|string|max:1000',
            'auto_translate' => 'nullable|boolean',
            'media' => 'nullable|array',
            'media.*' => 'nullable|string|max:2000',
            'partners' => 'nullable|array',
            'partners.*' => 'integer|exists:partners,id',
            'link_url' => 'nullable|url|max:2048',
            'location_name' => 'nullable|string|max:255',
            'location_id' => 'nullable|string|max:255',
            'status' => 'required|in:draft,scheduled',
            'publish_now' => 'nullable|boolean',
            'scheduled_at' => [$request->boolean('publish_now') ? 'nullable' : 'required_if:status,scheduled', 'nullable', 'date', 'after_or_equal:now'],
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();
        $publishNow = ! empty($validated['publish_now']);

        // Verify all selected accounts are accessible and active for this user
        $accountIds = $validated['accounts'];
        $validAccounts = $user->activeSocialAccounts()->whereIn('social_accounts.id', $accountIds)->get();

        if ($validAccounts->count() !== count($accountIds)) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => ['accounts' => ['One or more selected accounts are invalid.']]], 422);
            }

            return back()->withErrors(['accounts' => 'One or more selected accounts are invalid.'])->withInput();
        }

        $post = DB::transaction(function () use ($validated, $user, $validAccounts, $publishNow) {
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if ($publishNow) {
                $status = 'draft';
                $scheduledAt = null;
            }

            // Decode media JSON strings into arrays
            $media = null;
            if (! empty($validated['media'])) {
                $media = array_values(array_filter(array_map(function ($item) {
                    $decoded = is_string($item) ? json_decode($item, true) : $item;

                    return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
                }, $validated['media'])));
                if (empty($media)) {
                    $media = null;
                }
            }

            $post = Post::create([
                'user_id' => $user->id,
                'content_fr' => $validated['content_fr'],
                'content_en' => $validated['content_en'] ?? null,
                'article_title' => $validated['article_title'] ?? null,
                'platform_contents' => $this->filterPlatformContents($validated['platform_contents'] ?? null),
                'hashtags' => $validated['hashtags'] ?? null,
                'auto_translate' => true,
                'media' => $media,
                'link_url' => $validated['link_url'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'status' => $status,
                'scheduled_at' => $scheduledAt,
            ]);

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                    'status' => 'pending',
                ]);
            }

            // Tags partenaires : ceux coches a la main + ceux herites des photos.
            app(PartnerTagService::class)->syncPost($post, $validated['partners'] ?? []);

            return $post;
        });

        // Record hashtag usage
        if (! empty($validated['hashtags'])) {
            $this->recordHashtagsUsage($user->id, $validated['hashtags']);
        }

        // Return JSON for publish-now AJAX requests
        if ($publishNow && $request->expectsJson()) {
            $post->load('postPlatforms.socialAccount.platform');

            return response()->json([
                'success' => true,
                'post_id' => $post->id,
                'show_url' => route('posts.show', $post),
                'post_platforms' => $post->postPlatforms->map(fn ($pp) => [
                    'id' => $pp->id,
                    'account_name' => $pp->socialAccount->name,
                    'platform_slug' => $pp->socialAccount->platform->slug,
                    'publish_url' => route('posts.publishOne', $pp),
                ]),
            ]);
        }

        return redirect()->route('posts.index')
            ->with('success', 'Post created successfully.');
    }

    /**
     * Display the specified post with its platform details and logs.
     */
    public function show(Request $request, int $id): View
    {
        $user = $request->user();
        $userId = $user->id;

        $post = Post::with([
            'postPlatforms' => function ($q) use ($userId) {
                $q->whereHas('socialAccount', function ($sq) use ($userId) {
                    $sq->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
                });
            },
            'postPlatforms.platform',
            'postPlatforms.socialAccount',
            'postPlatforms.logs',
            'postPlatforms.snapshots',
            'partners',
            'user',
        ])->findOrFail($id);

        // Regular users can only view their own posts
        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            abort(403, 'Unauthorized.');
        }

        // Accounts the user can still add to this post (active, not already attached)
        $attachedAccountIds = $post->postPlatforms->pluck('social_account_id')->all();
        $availableAccounts = $user->activeSocialAccounts()
            ->with('platform')
            ->whereNotIn('social_accounts.id', $attachedAccountIds)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $partnerOptions = app(PartnerTagService::class)->options();

        return view('posts.show', compact('post', 'availableAccounts', 'partnerOptions'));
    }

    /**
     * Attach additional publishing platforms to an existing post without
     * disturbing the platforms that are already attached/published.
     */
    public function addPlatforms(Request $request, int $id): RedirectResponse
    {
        $post = Post::with('postPlatforms')->findOrFail($id);

        // Regular users can only modify their own posts
        if (! $request->user()->isAdmin() && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();

        // Only keep accounts that are active for this user
        $validAccounts = $user->activeSocialAccounts()
            ->whereIn('social_accounts.id', $validated['accounts'])
            ->get();

        // Skip accounts already attached to this post (no duplicates, no reset)
        $existingAccountIds = $post->postPlatforms->pluck('social_account_id')->all();

        $added = 0;
        foreach ($validAccounts as $account) {
            if (in_array($account->id, $existingAccountIds, true)) {
                continue;
            }

            PostPlatform::create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'platform_id' => $account->platform_id,
                'status' => 'pending',
            ]);
            $added++;
        }

        $message = $added > 0
            ? "{$added} plateforme(s) ajoutée(s). Cliquez sur « Publier » pour les diffuser."
            : 'Aucune nouvelle plateforme à ajouter.';

        return redirect()->route('posts.show', $post->id)->with('success', $message);
    }

    /**
     * Show the form for editing the specified post.
     */
    public function edit(Request $request, int $id): View
    {
        $post = Post::with('postPlatforms')->findOrFail($id);

        // Regular users can only edit their own posts
        if (! $request->user()->isAdmin() && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $user = $request->user();

        // Both admin and regular user see their own active accounts (per-user is_active)
        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->slug);

        $platforms = Platform::where('is_active', true)->get();

        // IDs of currently selected accounts
        $selectedAccountIds = $post->postPlatforms->pluck('social_account_id')->toArray();
        $charLimits = $this->getPlatformCharLimits();
        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();
        $partnerTags = app(PartnerTagService::class);
        $partnerOptions = $partnerTags->options();
        // Seuls les tags manuels sont re-cochables : les 'auto' sont recalcules au save.
        $selectedPartnerIds = $post->partners()->wherePivot('source', 'manual')->pluck('partners.id')->all();
        $mediaPartnerMap = $partnerTags->partnersByMediaUrl($post->media);

        // Une publication deja partie s'edite en metadonnees seules : ni comptes,
        // ni programmation, et surtout aucun renvoi vers les reseaux.
        $isPublished = $post->status === 'published';

        return view('posts.edit', compact('post', 'accounts', 'platforms', 'selectedAccountIds', 'charLimits', 'accountGroups', 'partnerOptions', 'selectedPartnerIds', 'mediaPartnerMap', 'isPublished'));
    }

    /**
     * Update the specified post and sync PostPlatform entries.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $post = Post::with('postPlatforms')->findOrFail($id);

        // Regular users can only update their own posts
        if (! $request->user()->isAdmin() && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        // Prevent editing posts that are already publishing or published
        if (in_array($post->status, ['publishing', 'published'])) {
            return back()->withErrors(['status' => 'Cannot edit a post that is already publishing or published.']);
        }

        $validated = $request->validate([
            // 30 000 : les posts longs X Premium montent à 25 000 caractères,
            // et un Article davantage encore.
            'content_fr' => 'required|string|max:30000',
            'content_en' => 'nullable|string|max:30000',
            'article_title' => 'nullable|string|max:255',
            'platform_contents' => 'nullable|array',
            'platform_contents.*' => 'nullable|string|max:30000',
            'hashtags' => 'nullable|string|max:1000',
            'auto_translate' => 'nullable|boolean',
            'media' => 'nullable|array',
            'media.*' => 'nullable|string|max:2000',
            'partners' => 'nullable|array',
            'partners.*' => 'integer|exists:partners,id',
            'link_url' => 'nullable|url|max:2048',
            'location_name' => 'nullable|string|max:255',
            'location_id' => 'nullable|string|max:255',
            'status' => 'required|in:draft,scheduled',
            'publish_now' => 'nullable|boolean',
            'scheduled_at' => [$request->boolean('publish_now') ? 'nullable' : 'required_if:status,scheduled', 'nullable', 'date', 'after_or_equal:now'],
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();
        $publishNow = ! empty($validated['publish_now']);

        // Verify all selected accounts are accessible and active for this user
        $accountIds = $validated['accounts'];
        $validAccounts = $user->activeSocialAccounts()->whereIn('social_accounts.id', $accountIds)->get();

        if ($validAccounts->count() !== count($accountIds)) {
            return back()->withErrors(['accounts' => 'One or more selected accounts are invalid.'])->withInput();
        }

        DB::transaction(function () use ($post, $validated, $validAccounts, $publishNow) {
            // If "publish now" is selected, override status and scheduled_at
            $status = $validated['status'];
            $scheduledAt = $validated['scheduled_at'] ?? null;

            if ($publishNow) {
                $status = 'scheduled';
                $scheduledAt = now();
            }

            // Decode media JSON strings into arrays
            $media = null;
            if (! empty($validated['media'])) {
                $media = array_values(array_filter(array_map(function ($item) {
                    $decoded = is_string($item) ? json_decode($item, true) : $item;

                    return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
                }, $validated['media'])));
                if (empty($media)) {
                    $media = null;
                }
            }

            // Update the post (clear translations cache when platform contents change)
            $post->update([
                'content_fr' => $validated['content_fr'],
                'content_en' => $validated['content_en'] ?? null,
                'article_title' => $validated['article_title'] ?? null,
                'platform_contents' => $this->filterPlatformContents($validated['platform_contents'] ?? null),
                'translations' => null,
                'hashtags' => $validated['hashtags'] ?? null,
                'auto_translate' => true,
                'media' => $media,
                'link_url' => $validated['link_url'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'status' => $status,
                'scheduled_at' => $scheduledAt,
            ]);

            // Sync PostPlatform entries: remove old ones and create new ones
            $post->postPlatforms()->delete();

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                    'status' => 'pending',
                ]);
            }

            // Les tags 'auto' suivent les photos actuelles ; les tags manuels sont
            // ceux du formulaire (le champ est toujours soumis, meme vide).
            app(PartnerTagService::class)->syncPost($post, $validated['partners'] ?? []);
        });

        // Record hashtag usage
        if (! empty($validated['hashtags'])) {
            $this->recordHashtagsUsage($user->id, $validated['hashtags']);
        }

        return redirect()->route('posts.show', $post->id)
            ->with('success', 'Post updated successfully.');
    }

    /**
     * Met à jour le contenu d'une publication DÉJÀ PUBLIÉE.
     *
     * Séparé de update() à dessein : ce dernier reconstruit les post_platform et
     * réenclenche une publication, ce qu'il ne faut surtout pas faire ici. On ne
     * touche donc qu'aux métadonnées — texte, médias, lieu, partenaires — sans
     * jamais rouvrir le circuit de publication ni changer le statut.
     *
     * **Rien n'est repoussé vers les réseaux** : la modification reste interne à
     * RS-Max (compte rendu, tag partenaire, correction de texte a posteriori).
     */
    public function updatePublished(Request $request, int $id): RedirectResponse
    {
        $post = Post::findOrFail($id);
        $user = $request->user();

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            abort(403, 'Unauthorized.');
        }

        // Une publication encore en cours d'envoi ne doit pas bouger sous les
        // pieds du job ; un brouillon passe, lui, par update().
        if ($post->status !== 'published') {
            return back()->withErrors([
                'status' => 'Cet écran ne modifie que les publications déjà publiées.',
            ]);
        }

        $validated = $request->validate([
            'content_fr' => 'required|string|max:30000',
            'content_en' => 'nullable|string|max:30000',
            'article_title' => 'nullable|string|max:255',
            'platform_contents' => 'nullable|array',
            'platform_contents.*' => 'nullable|string|max:30000',
            'hashtags' => 'nullable|string|max:1000',
            'media' => 'nullable|array',
            'media.*' => 'nullable|string|max:2000',
            'partners' => 'nullable|array',
            'partners.*' => 'integer|exists:partners,id',
            'link_url' => 'nullable|url|max:2048',
            'location_name' => 'nullable|string|max:255',
            'location_id' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($post, $validated) {
            $post->update([
                'content_fr' => $validated['content_fr'],
                'content_en' => $validated['content_en'] ?? null,
                'article_title' => $validated['article_title'] ?? null,
                'platform_contents' => $this->filterPlatformContents($validated['platform_contents'] ?? null),
                'translations' => null,
                'hashtags' => $validated['hashtags'] ?? null,
                'media' => $this->decodeMediaInput($validated['media'] ?? null),
                'link_url' => $validated['link_url'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
            ]);

            // Les post_platform ne sont PAS reconstruits : elles portent les
            // external_id des publications reellement parties.
            app(PartnerTagService::class)->syncPost($post, $validated['partners'] ?? []);
        });

        return redirect()->route('posts.show', $post->id)
            ->with('success', 'Publication mise à jour dans RS-Max. Les réseaux ne sont pas modifiés.');
    }

    /**
     * Décode les médias soumis par le formulaire (chaînes JSON) en tableau.
     */
    private function decodeMediaInput(?array $items): ?array
    {
        if (empty($items)) {
            return null;
        }

        $media = array_values(array_filter(array_map(function ($item) {
            $decoded = is_string($item) ? json_decode($item, true) : $item;

            return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
        }, $items)));

        return $media ?: null;
    }

    /**
     * Met à jour les seuls tags partenaires d'un post.
     *
     * Volontairement séparé de update() : ce dernier refuse les posts publiés,
     * alors qu'un tag partenaire est une métadonnée interne de reporting, qu'on
     * doit pouvoir poser rétroactivement sur une publication déjà partie.
     */
    public function updatePartners(Request $request, int $id): RedirectResponse
    {
        $post = Post::findOrFail($id);
        $user = $request->user();

        // Le propriétaire, ou un manager qui prépare un compte rendu.
        if (! $user->isManager() && $post->user_id !== $user->id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'partners' => 'nullable|array',
            'partners.*' => 'integer|exists:partners,id',
        ]);

        app(PartnerTagService::class)->syncPost($post, $validated['partners'] ?? []);

        return redirect()->route('posts.show', $post->id)
            ->with('success', 'Partenaires mis à jour.');
    }

    /**
     * Delete the specified post and cascade its related records.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $post = Post::findOrFail($id);

        // Regular users can only delete their own posts
        if (! $request->user()->isAdmin() && $post->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        // Prevent deleting posts that are currently publishing
        if ($post->status === 'publishing') {
            return back()->withErrors(['status' => 'Cannot delete a post that is currently being published.']);
        }

        DB::transaction(function () use ($post) {
            // Delete logs for all post platforms
            foreach ($post->postPlatforms as $postPlatform) {
                $postPlatform->logs()->delete();
            }

            // Delete post platforms
            $post->postPlatforms()->delete();

            // Delete the post
            $post->delete();
        });

        return redirect()->route('posts.index')
            ->with('success', 'Post deleted successfully.');
    }

    /**
     * Manually sync stats for a post (all platforms or specific platform).
     */
    public function syncStats(Request $request, int $id, StatsSyncService $syncService): JsonResponse
    {
        $post = Post::with('postPlatforms.platform')->findOrFail($id);

        // Authorization check
        if (! $request->user()->isAdmin() && $post->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Get platform filter if specified
        $platformSlug = $request->input('platform');

        $userId = $request->user()->id;
        $postPlatforms = $post->postPlatforms()
            ->with(['platform', 'socialAccount'])
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->whereHas('socialAccount', function ($q) use ($userId) {
                $q->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
            })
            ->when($platformSlug, fn ($q) => $q->whereHas('platform', fn ($pq) => $pq->where('slug', $platformSlug)))
            ->get();

        if ($postPlatforms->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'No published platforms found for this post.',
            ], 400);
        }

        $synced = 0;
        $failed = 0;

        foreach ($postPlatforms as $postPlatform) {
            try {
                if ($syncService->syncPostPlatform($postPlatform)) {
                    $synced++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'success' => true,
            'synced' => $synced,
            'failed' => $failed,
            'message' => "Synchronized {$synced} platform(s).",
        ]);
    }

    private function filterPlatformContents(?array $contents): ?array
    {
        if (empty($contents)) {
            return null;
        }

        $filtered = array_filter($contents, fn ($text) => ! empty(trim($text ?? '')));

        return ! empty($filtered) ? $filtered : null;
    }

    private function getPlatformCharLimits(): array
    {
        $defaults = [
            'twitter' => 280, 'facebook' => 63206, 'instagram' => 2200,
            'threads' => 500, 'youtube' => 5000, 'telegram' => 4096,
            'bluesky' => 300, 'linkedin' => 3000, 'pinterest' => 500, 'reddit' => 40000,
        ];

        $limits = [];
        foreach ($defaults as $slug => $default) {
            $limits[$slug] = (int) Setting::get("platform_char_limit_{$slug}", $default);
        }

        // Limite débloquée par un abonnement X Premium : POST /2/tweets accepte
        // jusqu'à 25 000 caractères depuis août 2024, mais seulement si le compte
        // qui publie est abonné (sinon erreur 111 « Tweet text is too long »).
        $limits['twitter_premium'] = (int) Setting::get('platform_char_limit_twitter_premium', 25000);

        return $limits;
    }

    /**
     * Parse and record hashtag usage for the user
     */
    private function recordHashtagsUsage(int $userId, string $hashtagsString): void
    {
        // Parse hashtags string (can be space or comma separated)
        $hashtags = preg_split('/[\s,]+/', $hashtagsString, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($hashtags as $tag) {
            Hashtag::recordUsage($userId, $tag);
        }
    }
}
