<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WpPost;
use App\Models\WpSource;
use App\Services\Rss\ContentGenerationService;
use App\Services\WordPress\WordPressFetchService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WordPressSiteController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $sources = WpSource::withCount('wpItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('wordpress.index', compact('sources'));
    }

    public function create(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        return view('wordpress.create', compact('accounts', 'personas'));
    }

    public function store(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'auth_username' => 'nullable|string|max:255',
            'auth_password' => 'nullable|string|max:255',
            'post_types' => 'required|array|min:1',
            'post_types.*' => 'string|max:50',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'schedule_frequency' => 'in:daily,twice_weekly,weekly,biweekly,monthly',
            'schedule_time' => 'nullable|date_format:H:i',
            'is_active' => 'boolean',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $source = WpSource::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'auth_username' => $validated['auth_username'] ?? null,
            'auth_password' => $validated['auth_password'] ?? null,
            'post_types' => $validated['post_types'],
            'categories' => $validated['categories'] ?? null,
            'schedule_frequency' => $validated['schedule_frequency'] ?? 'weekly',
            'schedule_time' => $validated['schedule_time'] ?? '10:00',
            'is_active' => $request->boolean('is_active', true),
        ]);

        if (! empty($validated['accounts'])) {
            $syncData = [];
            foreach ($validated['accounts'] as $accountData) {
                $syncData[$accountData['id']] = [
                    'persona_id' => $accountData['persona_id'] ?? null,
                    'auto_post' => $accountData['auto_post'] ?? false,
                    'post_frequency' => $accountData['post_frequency'] ?? 'daily',
                    'max_posts_per_day' => $accountData['max_posts_per_day'] ?? 1,
                ];
            }
            $source->socialAccounts()->sync($syncData);
        }

        return redirect()->route('wordpress-sites.index')->with('status', 'source-created');
    }

    public function edit(Request $request, WpSource $wpSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $wpSource->load('socialAccounts');

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        $linkedAccounts = [];
        foreach ($wpSource->socialAccounts as $account) {
            $linkedAccounts[$account->id] = [
                'persona_id' => $account->pivot->persona_id,
                'auto_post' => $account->pivot->auto_post,
                'post_frequency' => $account->pivot->post_frequency,
                'max_posts_per_day' => $account->pivot->max_posts_per_day,
            ];
        }

        return view('wordpress.edit', compact('wpSource', 'accounts', 'personas', 'linkedAccounts'));
    }

    public function update(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'auth_username' => 'nullable|string|max:255',
            'auth_password' => 'nullable|string|max:255',
            'post_types' => 'required|array|min:1',
            'post_types.*' => 'string|max:50',
            'categories' => 'nullable|array',
            'categories.*' => 'integer',
            'schedule_frequency' => 'in:daily,twice_weekly,weekly,biweekly,monthly',
            'schedule_time' => 'nullable|date_format:H:i',
            'is_active' => 'boolean',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'auth_username' => $validated['auth_username'] ?? null,
            'post_types' => $validated['post_types'],
            'categories' => $validated['categories'] ?? null,
            'schedule_frequency' => $validated['schedule_frequency'] ?? 'weekly',
            'schedule_time' => $validated['schedule_time'] ?? '10:00',
            'is_active' => $request->boolean('is_active', true),
        ];

        // Only update password if provided (don't clear existing one)
        if (! empty($validated['auth_password'])) {
            $updateData['auth_password'] = $validated['auth_password'];
        }

        $wpSource->update($updateData);

        $syncData = [];
        if (! empty($validated['accounts'])) {
            foreach ($validated['accounts'] as $accountData) {
                $syncData[$accountData['id']] = [
                    'persona_id' => $accountData['persona_id'] ?? null,
                    'auto_post' => $accountData['auto_post'] ?? false,
                    'post_frequency' => $accountData['post_frequency'] ?? 'daily',
                    'max_posts_per_day' => $accountData['max_posts_per_day'] ?? 1,
                ];
            }
        }
        $wpSource->socialAccounts()->sync($syncData);

        return redirect()->route('wordpress-sites.index')->with('status', 'source-updated');
    }

    public function destroy(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $wpSource->delete();

        return redirect()->route('wordpress-sites.index')->with('status', 'source-deleted');
    }

    // ─── AJAX: Test connection ───────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $request->validate([
            'url' => 'required|url',
            'auth_username' => 'nullable|string',
            'auth_password' => 'nullable|string',
        ]);

        $service = new WordPressFetchService;
        $result = $service->testConnection(
            $request->input('url'),
            $request->input('auth_username'),
            $request->input('auth_password'),
        );

        return response()->json($result);
    }

    // ─── Fetch ───────────────────────────────────────────────────────

    public function fetchNow(Request $request, WpSource $wpSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $service = new WordPressFetchService;
        $count = $service->fetchSource($wpSource);

        return redirect()->route('wordpress-sites.index')
            ->with('status', 'source-fetched')
            ->with('fetch_count', $count);
    }

    // ─── Preview page ────────────────────────────────────────────────

    public function preview(Request $request, WpSource $wpSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $wpSource->load(['socialAccounts.platform', 'wpItems']);

        $totalItems = $wpSource->wpItems()->count();

        $lastPostDate = $this->getLastPostDate($wpSource);
        $nextDate = $this->calculateNextDate($lastPostDate, $wpSource->schedule_frequency, $wpSource->schedule_time);

        $frequencyLabels = [
            'daily' => 'Quotidien',
            'twice_weekly' => '2x par semaine',
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Tous les 15 jours',
            'monthly' => 'Mensuel',
        ];

        // Get post type labels for display
        $postTypeLabels = $wpSource->post_types ?? [];

        return view('wordpress.preview', compact('wpSource', 'totalItems', 'nextDate', 'frequencyLabels', 'postTypeLabels'));
    }

    public function generatePreview(Request $request, WpSource $wpSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $count = (int) $request->input('count', 3);
        $count = max(1, min(20, $count));

        $wpSource->load(['socialAccounts.platform']);

        if ($wpSource->socialAccounts->isEmpty()) {
            return response()->json(['error' => 'Aucun compte social lié à cette source.'], 422);
        }

        // Select the least-used items first (balanced reuse)
        $items = $wpSource->wpItems()
            ->select('wp_items.*')
            ->selectRaw('(SELECT COUNT(*) FROM wp_posts WHERE wp_posts.wp_item_id = wp_items.id) as usage_count')
            ->orderByRaw('usage_count ASC, RAND()')
            ->limit($count)
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['error' => 'Aucun article disponible. Récupérez le contenu du site d\'abord.'], 422);
        }

        $results = [];

        $lastPostDate = $this->getLastPostDate($wpSource);
        $dates = [];
        for ($i = 0; $i < $items->count(); $i++) {
            $lastPostDate = $this->calculateNextDate($lastPostDate, $wpSource->schedule_frequency, $wpSource->schedule_time);
            $dates[] = $lastPostDate->copy();
        }

        foreach ($items as $index => $item) {
            $platformContents = [];

            foreach ($wpSource->socialAccounts as $account) {
                $platformContents[$account->id] = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'platform_slug' => $account->platform->slug,
                    'platform_name' => $account->platform->name,
                    'content' => '',
                    'error' => null,
                ];
            }

            $results[] = [
                'wp_item_id' => $item->id,
                'title' => $item->title,
                'url' => $item->url,
                'post_type' => $item->post_type,
                'usage_count' => $item->usage_count,
                'scheduled_at' => $dates[$index]->format('Y-m-d H:i'),
                'scheduled_at_human' => $dates[$index]->translatedFormat('l j F Y à H:i'),
                'platform_contents' => $platformContents,
            ];
        }

        return response()->json(['publications' => $results]);
    }

    public function regenerateItem(Request $request, WpSource $wpSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $itemId = $request->input('wp_item_id');
        $item = $wpSource->wpItems()->findOrFail($itemId);

        $wpSource->load(['socialAccounts.platform']);
        $generationService = new ContentGenerationService;
        $platformContents = [];

        foreach ($wpSource->socialAccounts as $account) {
            $persona = $account->pivot->persona_id ? Persona::find($account->pivot->persona_id) : null;

            if (! $persona) {
                $platformContents[$account->id] = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'platform_slug' => $account->platform->slug,
                    'platform_name' => $account->platform->name,
                    'content' => '',
                    'error' => 'Aucun persona configuré pour ce compte.',
                ];

                continue;
            }

            $content = $generationService->generate($item, $persona, $account);

            $platformContents[$account->id] = [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'platform_slug' => $account->platform->slug,
                'platform_name' => $account->platform->name,
                'content' => $content ?? '',
                'error' => $content === null ? 'Erreur lors de la génération.' : null,
            ];

            usleep(300000);
        }

        return response()->json(['platform_contents' => $platformContents]);
    }

    public function confirmPublications(Request $request, WpSource $wpSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $publications = $request->input('publications', []);

        if (empty($publications)) {
            return response()->json(['error' => 'Aucune publication à créer.'], 422);
        }

        $adminUser = User::where('is_admin', true)->first();
        if (! $adminUser) {
            return response()->json(['error' => 'Aucun utilisateur admin trouvé.'], 500);
        }

        $wpSource->load(['socialAccounts.platform']);

        $created = 0;

        DB::transaction(function () use ($publications, $wpSource, $adminUser, &$created) {
            foreach ($publications as $pub) {
                $wpItemId = $pub['wp_item_id'];
                $scheduledAt = Carbon::parse($pub['scheduled_at']);
                $platformContents = $pub['platform_contents'] ?? [];

                $contentsBySlug = [];
                $mainContent = '';

                foreach ($platformContents as $accountId => $data) {
                    $content = $data['content'] ?? '';
                    if (! $mainContent && $content) {
                        $mainContent = $content;
                    }

                    $account = $wpSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if ($account) {
                        $contentsBySlug[$account->platform->slug] = $content;
                    }
                }

                $item = $wpSource->wpItems()->find($wpItemId);
                if (! $item) {
                    continue;
                }

                $post = Post::create([
                    'user_id' => $adminUser->id,
                    'content_fr' => $mainContent,
                    'platform_contents' => $contentsBySlug,
                    'link_url' => $item->url,
                    'source_type' => 'wordpress',
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt,
                    'auto_translate' => true,
                ]);

                foreach ($platformContents as $accountId => $data) {
                    $account = $wpSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if (! $account) {
                        continue;
                    }

                    PostPlatform::create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $account->platform_id,
                        'status' => 'pending',
                    ]);
                }

                foreach ($platformContents as $accountId => $data) {
                    $account = $wpSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if (! $account) {
                        continue;
                    }

                    WpPost::create([
                        'wp_item_id' => $wpItemId,
                        'social_account_id' => $account->id,
                        'persona_id' => $account->pivot->persona_id,
                        'post_id' => $post->id,
                        'generated_content' => $data['content'] ?? '',
                        'status' => 'generated',
                    ]);
                }

                $created++;
            }
        });

        return response()->json([
            'success' => true,
            'created' => $created,
            'message' => "{$created} publication(s) planifiée(s) avec succès.",
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function getLastPostDate(WpSource $wpSource): Carbon
    {
        $lastDate = Post::where('source_type', 'wordpress')
            ->whereIn('status', ['scheduled', 'published'])
            ->whereHas('postPlatforms', function ($q) use ($wpSource) {
                $q->whereIn('social_account_id', $wpSource->socialAccounts->pluck('id'));
            })
            ->max('scheduled_at');

        return $lastDate ? Carbon::parse($lastDate) : now();
    }

    private function calculateNextDate(Carbon $from, string $frequency, ?string $time): Carbon
    {
        $next = $from->copy();

        $next = match ($frequency) {
            'daily' => $next->addDay(),
            'twice_weekly' => $this->nextTwiceWeekly($next),
            'weekly' => $next->addWeek(),
            'biweekly' => $next->addWeeks(2),
            'monthly' => $next->addMonth(),
            default => $next->addWeek(),
        };

        if ($time) {
            [$hour, $minute] = explode(':', $time);
            $next->setTime((int) $hour, (int) $minute);
        }

        if ($next->isPast()) {
            return $this->calculateNextDate($next, $frequency, $time);
        }

        return $next;
    }

    private function nextTwiceWeekly(Carbon $date): Carbon
    {
        $dayOfWeek = $date->dayOfWeek;

        if ($dayOfWeek < Carbon::MONDAY) {
            return $date->next(Carbon::MONDAY);
        }
        if ($dayOfWeek < Carbon::THURSDAY) {
            return $date->next(Carbon::THURSDAY);
        }

        return $date->next(Carbon::MONDAY);
    }
}
