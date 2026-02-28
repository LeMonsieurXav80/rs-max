<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\RssFeed;
use App\Models\RssPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Rss\ContentGenerationService;
use App\Services\Rss\RssFetchService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RssFeedController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $feeds = RssFeed::withCount('rssItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('rss.index', compact('feeds'));
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

        return view('rss.create', compact('accounts', 'personas'));
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
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'is_multi_part_sitemap' => 'boolean',
            'schedule_frequency' => 'in:daily,twice_weekly,weekly,biweekly,monthly',
            'schedule_time' => 'nullable|date_format:H:i',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $feed = RssFeed::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_multi_part_sitemap' => $request->boolean('is_multi_part_sitemap', false),
            'schedule_frequency' => $validated['schedule_frequency'] ?? 'weekly',
            'schedule_time' => $validated['schedule_time'] ?? '10:00',
        ]);

        // Sync accounts with pivot data
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
            $feed->socialAccounts()->sync($syncData);
        }

        return redirect()->route('rss-feeds.index')->with('status', 'feed-created');
    }

    public function edit(Request $request, RssFeed $rssFeed): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $rssFeed->load('socialAccounts');

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        // Build linked accounts data for the form
        $linkedAccounts = [];
        foreach ($rssFeed->socialAccounts as $account) {
            $linkedAccounts[$account->id] = [
                'persona_id' => $account->pivot->persona_id,
                'auto_post' => $account->pivot->auto_post,
                'post_frequency' => $account->pivot->post_frequency,
                'max_posts_per_day' => $account->pivot->max_posts_per_day,
            ];
        }

        return view('rss.edit', compact('rssFeed', 'accounts', 'personas', 'linkedAccounts'));
    }

    public function update(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'is_multi_part_sitemap' => 'boolean',
            'schedule_frequency' => 'in:daily,twice_weekly,weekly,biweekly,monthly',
            'schedule_time' => 'nullable|date_format:H:i',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $rssFeed->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_multi_part_sitemap' => $request->boolean('is_multi_part_sitemap', false),
            'schedule_frequency' => $validated['schedule_frequency'] ?? 'weekly',
            'schedule_time' => $validated['schedule_time'] ?? '10:00',
        ]);

        // Sync accounts with pivot data
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
        $rssFeed->socialAccounts()->sync($syncData);

        return redirect()->route('rss-feeds.index')->with('status', 'feed-updated');
    }

    public function destroy(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $rssFeed->delete();

        return redirect()->route('rss-feeds.index')->with('status', 'feed-deleted');
    }

    public function fetchNow(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $service = new RssFetchService;
        $count = $service->fetchFeed($rssFeed);

        return redirect()->route('rss-feeds.index')
            ->with('status', 'feed-fetched')
            ->with('fetch_count', $count);
    }

    public function generateNow(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        Artisan::call('rss:generate', ['--feed' => $rssFeed->id]);
        $output = trim(Artisan::output());

        return redirect()->route('rss-feeds.index')
            ->with('status', 'feed-generated')
            ->with('generate_output', $output);
    }

    // ─── Preview page ───────────────────────────────────────────────

    public function preview(Request $request, RssFeed $rssFeed): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $rssFeed->load(['socialAccounts.platform', 'rssItems']);

        $totalItems = $rssFeed->rssItems()->count();

        // Calculate next available date based on last scheduled/published post for this feed
        $lastPostDate = $this->getLastPostDate($rssFeed);
        $nextDate = $this->calculateNextDate($lastPostDate, $rssFeed->schedule_frequency, $rssFeed->schedule_time);

        $frequencyLabels = [
            'daily' => 'Quotidien',
            'twice_weekly' => '2x par semaine',
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Tous les 15 jours',
            'monthly' => 'Mensuel',
        ];

        return view('rss.preview', compact('rssFeed', 'totalItems', 'nextDate', 'frequencyLabels'));
    }

    public function generatePreview(Request $request, RssFeed $rssFeed): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $count = (int) $request->input('count', 3);
        $count = max(1, min(20, $count));

        $rssFeed->load(['socialAccounts.platform']);

        if ($rssFeed->socialAccounts->isEmpty()) {
            return response()->json(['error' => 'Aucun compte social lié à ce flux.'], 422);
        }

        // Select the least-used items first (balanced reuse) — NO AI call here, fast response
        $items = $rssFeed->rssItems()
            ->select('rss_items.*')
            ->selectRaw('(SELECT COUNT(*) FROM rss_posts WHERE rss_posts.rss_item_id = rss_items.id) as usage_count')
            ->orderByRaw('usage_count ASC, RAND()')
            ->limit($count)
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['error' => 'Aucun article disponible dans ce flux. Récupérez le flux d\'abord.'], 422);
        }

        $results = [];

        // Calculate dates for each publication
        $lastPostDate = $this->getLastPostDate($rssFeed);
        $dates = [];
        for ($i = 0; $i < $items->count(); $i++) {
            $lastPostDate = $this->calculateNextDate($lastPostDate, $rssFeed->schedule_frequency, $rssFeed->schedule_time);
            $dates[] = $lastPostDate->copy();
        }

        // Build empty platform_contents structure (content will be generated item-by-item via regenerateItem)
        foreach ($items as $index => $item) {
            $platformContents = [];

            foreach ($rssFeed->socialAccounts as $account) {
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
                'rss_item_id' => $item->id,
                'title' => $item->title,
                'url' => $item->url,
                'usage_count' => $item->usage_count,
                'scheduled_at' => $dates[$index]->format('Y-m-d H:i'),
                'scheduled_at_human' => $dates[$index]->translatedFormat('l j F Y à H:i'),
                'platform_contents' => $platformContents,
            ];
        }

        return response()->json(['publications' => $results]);
    }

    public function regenerateItem(Request $request, RssFeed $rssFeed): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $itemId = $request->input('rss_item_id');
        $item = $rssFeed->rssItems()->findOrFail($itemId);

        $rssFeed->load(['socialAccounts.platform']);
        $generationService = new ContentGenerationService;
        $platformContents = [];

        foreach ($rssFeed->socialAccounts as $account) {
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

    public function confirmPublications(Request $request, RssFeed $rssFeed): JsonResponse
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

        $rssFeed->load(['socialAccounts.platform']);

        $created = 0;

        DB::transaction(function () use ($publications, $rssFeed, $adminUser, &$created) {
            foreach ($publications as $pub) {
                $rssItemId = $pub['rss_item_id'];
                $scheduledAt = Carbon::parse($pub['scheduled_at']);
                $platformContents = $pub['platform_contents'] ?? [];

                // Build platform_contents array keyed by slug
                $contentsBySlug = [];
                $mainContent = '';

                foreach ($platformContents as $accountId => $data) {
                    $content = $data['content'] ?? '';
                    if (! $mainContent && $content) {
                        $mainContent = $content;
                    }

                    // Find the platform slug for this account
                    $account = $rssFeed->socialAccounts->firstWhere('id', (int) $accountId);
                    if ($account) {
                        $contentsBySlug[$account->platform->slug] = $content;
                    }
                }

                $item = $rssFeed->rssItems()->find($rssItemId);
                if (! $item) {
                    continue;
                }

                // Create the Post
                $post = Post::create([
                    'user_id' => $adminUser->id,
                    'content_fr' => $mainContent,
                    'platform_contents' => $contentsBySlug,
                    'link_url' => $item->url,
                    'source_type' => 'rss',
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt,
                    'auto_translate' => true,
                ]);

                // Create PostPlatform entries for each linked account
                foreach ($platformContents as $accountId => $data) {
                    $account = $rssFeed->socialAccounts->firstWhere('id', (int) $accountId);
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

                // Create RssPost tracking entries
                foreach ($platformContents as $accountId => $data) {
                    $account = $rssFeed->socialAccounts->firstWhere('id', (int) $accountId);
                    if (! $account) {
                        continue;
                    }

                    RssPost::create([
                        'rss_item_id' => $rssItemId,
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

    private function getLastPostDate(RssFeed $rssFeed): Carbon
    {
        $lastDate = Post::where('source_type', 'rss')
            ->whereIn('status', ['scheduled', 'published'])
            ->whereHas('postPlatforms', function ($q) use ($rssFeed) {
                $q->whereIn('social_account_id', $rssFeed->socialAccounts->pluck('id'));
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

        // Set the time
        if ($time) {
            [$hour, $minute] = explode(':', $time);
            $next->setTime((int) $hour, (int) $minute);
        }

        // Ensure date is in the future
        if ($next->isPast()) {
            return $this->calculateNextDate($next, $frequency, $time);
        }

        return $next;
    }

    private function nextTwiceWeekly(Carbon $date): Carbon
    {
        // Publish on Monday and Thursday
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
