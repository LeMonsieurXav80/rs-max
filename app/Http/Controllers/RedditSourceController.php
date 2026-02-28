<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\RedditPost;
use App\Models\RedditSource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Rss\ContentGenerationService;
use App\Services\Reddit\RedditFetchService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RedditSourceController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $sources = RedditSource::withCount('redditItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('reddit.index', compact('sources'));
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

        return view('reddit.create', compact('accounts', 'personas'));
    }

    public function store(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subreddit' => 'required|string|max:100',
            'sort_by' => 'in:hot,new,top,rising',
            'time_filter' => 'in:hour,day,week,month,year,all',
            'min_score' => 'nullable|integer|min:0',
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

        $source = RedditSource::create([
            'name' => $validated['name'],
            'subreddit' => $validated['subreddit'],
            'sort_by' => $validated['sort_by'] ?? 'hot',
            'time_filter' => $validated['time_filter'] ?? 'week',
            'min_score' => $validated['min_score'] ?? 0,
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

        return redirect()->route('reddit-sources.index')->with('status', 'source-created');
    }

    public function edit(Request $request, RedditSource $redditSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $redditSource->load('socialAccounts');

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        $linkedAccounts = [];
        foreach ($redditSource->socialAccounts as $account) {
            $linkedAccounts[$account->id] = [
                'persona_id' => $account->pivot->persona_id,
                'auto_post' => $account->pivot->auto_post,
                'post_frequency' => $account->pivot->post_frequency,
                'max_posts_per_day' => $account->pivot->max_posts_per_day,
            ];
        }

        return view('reddit.edit', compact('redditSource', 'accounts', 'personas', 'linkedAccounts'));
    }

    public function update(Request $request, RedditSource $redditSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subreddit' => 'required|string|max:100',
            'sort_by' => 'in:hot,new,top,rising',
            'time_filter' => 'in:hour,day,week,month,year,all',
            'min_score' => 'nullable|integer|min:0',
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

        $redditSource->update([
            'name' => $validated['name'],
            'subreddit' => $validated['subreddit'],
            'sort_by' => $validated['sort_by'] ?? 'hot',
            'time_filter' => $validated['time_filter'] ?? 'week',
            'min_score' => $validated['min_score'] ?? 0,
            'schedule_frequency' => $validated['schedule_frequency'] ?? 'weekly',
            'schedule_time' => $validated['schedule_time'] ?? '10:00',
            'is_active' => $request->boolean('is_active', true),
        ]);

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
        $redditSource->socialAccounts()->sync($syncData);

        return redirect()->route('reddit-sources.index')->with('status', 'source-updated');
    }

    public function destroy(Request $request, RedditSource $redditSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $redditSource->delete();

        return redirect()->route('reddit-sources.index')->with('status', 'source-deleted');
    }

    // ─── AJAX: Test connection ───────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $request->validate([
            'subreddit' => 'required|string',
        ]);

        $service = new RedditFetchService;
        $result = $service->testConnection($request->input('subreddit'));

        return response()->json($result);
    }

    // ─── Fetch ───────────────────────────────────────────────────────

    public function fetchNow(Request $request, RedditSource $redditSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $service = new RedditFetchService;
        $count = $service->fetchSource($redditSource);

        return redirect()->route('reddit-sources.index')
            ->with('status', 'source-fetched')
            ->with('fetch_count', $count);
    }

    // ─── Preview page ────────────────────────────────────────────────

    public function preview(Request $request, RedditSource $redditSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $redditSource->load(['socialAccounts.platform', 'redditItems']);

        $totalItems = $redditSource->redditItems()->count();

        $lastPostDate = $this->getLastPostDate($redditSource);
        $nextDate = $this->calculateNextDate($lastPostDate, $redditSource->schedule_frequency, $redditSource->schedule_time);

        $frequencyLabels = [
            'daily' => 'Quotidien',
            'twice_weekly' => '2x par semaine',
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Tous les 15 jours',
            'monthly' => 'Mensuel',
        ];

        return view('reddit.preview', compact('redditSource', 'totalItems', 'nextDate', 'lastPostDate', 'frequencyLabels'));
    }

    public function generatePreview(Request $request, RedditSource $redditSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $count = (int) $request->input('count', 3);
        $count = max(1, min(20, $count));

        $redditSource->load(['socialAccounts.platform']);

        if ($redditSource->socialAccounts->isEmpty()) {
            return response()->json(['error' => 'Aucun compte social lié à cette source.'], 422);
        }

        $items = $redditSource->redditItems()
            ->select('reddit_items.*')
            ->selectRaw('(SELECT COUNT(*) FROM reddit_posts WHERE reddit_posts.reddit_item_id = reddit_items.id) as usage_count')
            ->orderByRaw('usage_count ASC, RAND()')
            ->limit($count)
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['error' => "Aucun post disponible. Récupérez le contenu du subreddit d'abord."], 422);
        }

        $results = [];

        $lastPostDate = $this->getLastPostDate($redditSource);
        $dates = [];
        for ($i = 0; $i < $items->count(); $i++) {
            $lastPostDate = $this->calculateNextDate($lastPostDate, $redditSource->schedule_frequency, $redditSource->schedule_time);
            $dates[] = $lastPostDate->copy();
        }

        foreach ($items as $index => $item) {
            $platformContents = [];

            foreach ($redditSource->socialAccounts as $account) {
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
                'reddit_item_id' => $item->id,
                'title' => $item->title,
                'url' => $item->url,
                'permalink' => $item->permalink,
                'score' => $item->score,
                'num_comments' => $item->num_comments,
                'author' => $item->author,
                'is_self' => $item->is_self,
                'usage_count' => $item->usage_count,
                'scheduled_at' => $dates[$index]->format('Y-m-d H:i'),
                'scheduled_at_human' => $dates[$index]->translatedFormat('l j F Y à H:i'),
                'platform_contents' => $platformContents,
            ];
        }

        return response()->json(['publications' => $results]);
    }

    public function regenerateItem(Request $request, RedditSource $redditSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $itemId = $request->input('reddit_item_id');
        $item = $redditSource->redditItems()->findOrFail($itemId);

        $redditSource->load(['socialAccounts.platform']);
        $generationService = new ContentGenerationService;
        $platformContents = [];

        foreach ($redditSource->socialAccounts as $account) {
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

    public function confirmPublications(Request $request, RedditSource $redditSource): JsonResponse
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

        $redditSource->load(['socialAccounts.platform']);

        $created = 0;

        DB::transaction(function () use ($publications, $redditSource, $adminUser, &$created) {
            foreach ($publications as $pub) {
                $redditItemId = $pub['reddit_item_id'];
                $scheduledAt = Carbon::parse($pub['scheduled_at']);
                $platformContents = $pub['platform_contents'] ?? [];

                $contentsBySlug = [];
                $mainContent = '';

                foreach ($platformContents as $accountId => $data) {
                    $content = $data['content'] ?? '';
                    if (! $mainContent && $content) {
                        $mainContent = $content;
                    }

                    $account = $redditSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if ($account) {
                        $contentsBySlug[$account->platform->slug] = $content;
                    }
                }

                $item = $redditSource->redditItems()->find($redditItemId);
                if (! $item) {
                    continue;
                }

                $post = Post::create([
                    'user_id' => $adminUser->id,
                    'content_fr' => $mainContent,
                    'platform_contents' => $contentsBySlug,
                    'link_url' => $item->permalink,
                    'source_type' => 'reddit',
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt,
                    'auto_translate' => true,
                ]);

                foreach ($platformContents as $accountId => $data) {
                    $account = $redditSource->socialAccounts->firstWhere('id', (int) $accountId);
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
                    $account = $redditSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if (! $account) {
                        continue;
                    }

                    RedditPost::create([
                        'reddit_item_id' => $redditItemId,
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

    private function getLastPostDate(RedditSource $redditSource): Carbon
    {
        $lastDate = Post::where('source_type', 'reddit')
            ->whereIn('status', ['scheduled', 'published'])
            ->whereHas('postPlatforms', function ($q) use ($redditSource) {
                $q->whereIn('social_account_id', $redditSource->socialAccounts->pluck('id'));
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
