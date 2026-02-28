<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\YtPost;
use App\Models\YtSource;
use App\Services\Rss\ContentGenerationService;
use App\Services\YouTube\YouTubeFetchService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class YouTubeChannelController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $sources = YtSource::withCount('ytItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('youtube.index', compact('sources'));
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

        return view('youtube.create', compact('accounts', 'personas'));
    }

    public function store(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'channel_url' => 'required|string|max:2000',
            'channel_id' => 'required|string|max:50',
            'channel_name' => 'nullable|string|max:255',
            'thumbnail_url' => 'nullable|string|max:2000',
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

        $source = YtSource::create([
            'name' => $validated['name'],
            'channel_url' => $validated['channel_url'],
            'channel_id' => $validated['channel_id'],
            'channel_name' => $validated['channel_name'] ?? null,
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
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

        return redirect()->route('youtube-channels.index')->with('status', 'source-created');
    }

    public function edit(Request $request, YtSource $ytSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $ytSource->load('socialAccounts');

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        $linkedAccounts = [];
        foreach ($ytSource->socialAccounts as $account) {
            $linkedAccounts[$account->id] = [
                'persona_id' => $account->pivot->persona_id,
                'auto_post' => $account->pivot->auto_post,
                'post_frequency' => $account->pivot->post_frequency,
                'max_posts_per_day' => $account->pivot->max_posts_per_day,
            ];
        }

        return view('youtube.edit', compact('ytSource', 'accounts', 'personas', 'linkedAccounts'));
    }

    public function update(Request $request, YtSource $ytSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'channel_url' => 'required|string|max:2000',
            'channel_id' => 'required|string|max:50',
            'channel_name' => 'nullable|string|max:255',
            'thumbnail_url' => 'nullable|string|max:2000',
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

        $ytSource->update([
            'name' => $validated['name'],
            'channel_url' => $validated['channel_url'],
            'channel_id' => $validated['channel_id'],
            'channel_name' => $validated['channel_name'] ?? null,
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
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
        $ytSource->socialAccounts()->sync($syncData);

        return redirect()->route('youtube-channels.index')->with('status', 'source-updated');
    }

    public function destroy(Request $request, YtSource $ytSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $ytSource->delete();

        return redirect()->route('youtube-channels.index')->with('status', 'source-deleted');
    }

    // ─── AJAX: Test connection ───────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $request->validate([
            'channel_url' => 'required|string',
        ]);

        $service = new YouTubeFetchService;
        $result = $service->testConnection($request->input('channel_url'));

        return response()->json($result);
    }

    // ─── Fetch ───────────────────────────────────────────────────────

    public function fetchNow(Request $request, YtSource $ytSource)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $service = new YouTubeFetchService;
        $count = $service->fetchSource($ytSource);

        return redirect()->route('youtube-channels.index')
            ->with('status', 'source-fetched')
            ->with('fetch_count', $count);
    }

    // ─── Preview page ────────────────────────────────────────────────

    public function preview(Request $request, YtSource $ytSource): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $ytSource->load(['socialAccounts.platform', 'ytItems']);

        $totalItems = $ytSource->ytItems()->count();

        $lastPostDate = $this->getLastPostDate($ytSource);
        $nextDate = $this->calculateNextDate($lastPostDate, $ytSource->schedule_frequency, $ytSource->schedule_time);

        $frequencyLabels = [
            'daily' => 'Quotidien',
            'twice_weekly' => '2x par semaine',
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Tous les 15 jours',
            'monthly' => 'Mensuel',
        ];

        return view('youtube.preview', compact('ytSource', 'totalItems', 'nextDate', 'frequencyLabels'));
    }

    public function generatePreview(Request $request, YtSource $ytSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $count = (int) $request->input('count', 3);
        $count = max(1, min(20, $count));

        $ytSource->load(['socialAccounts.platform']);

        if ($ytSource->socialAccounts->isEmpty()) {
            return response()->json(['error' => 'Aucun compte social lié à cette source.'], 422);
        }

        $items = $ytSource->ytItems()
            ->select('yt_items.*')
            ->selectRaw('(SELECT COUNT(*) FROM yt_posts WHERE yt_posts.yt_item_id = yt_items.id) as usage_count')
            ->orderByRaw('usage_count ASC, RAND()')
            ->limit($count)
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['error' => "Aucune vidéo disponible. Récupérez le contenu de la chaîne d'abord."], 422);
        }

        $results = [];

        $lastPostDate = $this->getLastPostDate($ytSource);
        $dates = [];
        for ($i = 0; $i < $items->count(); $i++) {
            $lastPostDate = $this->calculateNextDate($lastPostDate, $ytSource->schedule_frequency, $ytSource->schedule_time);
            $dates[] = $lastPostDate->copy();
        }

        foreach ($items as $index => $item) {
            $platformContents = [];

            foreach ($ytSource->socialAccounts as $account) {
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
                'yt_item_id' => $item->id,
                'title' => $item->title,
                'url' => $item->url,
                'thumbnail_url' => $item->thumbnail_url,
                'duration' => $item->duration,
                'view_count' => $item->view_count,
                'usage_count' => $item->usage_count,
                'scheduled_at' => $dates[$index]->format('Y-m-d H:i'),
                'scheduled_at_human' => $dates[$index]->translatedFormat('l j F Y à H:i'),
                'platform_contents' => $platformContents,
            ];
        }

        return response()->json(['publications' => $results]);
    }

    public function regenerateItem(Request $request, YtSource $ytSource): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $itemId = $request->input('yt_item_id');
        $item = $ytSource->ytItems()->findOrFail($itemId);

        $ytSource->load(['socialAccounts.platform']);
        $generationService = new ContentGenerationService;
        $platformContents = [];

        foreach ($ytSource->socialAccounts as $account) {
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

    public function confirmPublications(Request $request, YtSource $ytSource): JsonResponse
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

        $ytSource->load(['socialAccounts.platform']);

        $created = 0;

        DB::transaction(function () use ($publications, $ytSource, $adminUser, &$created) {
            foreach ($publications as $pub) {
                $ytItemId = $pub['yt_item_id'];
                $scheduledAt = Carbon::parse($pub['scheduled_at']);
                $platformContents = $pub['platform_contents'] ?? [];

                $contentsBySlug = [];
                $mainContent = '';

                foreach ($platformContents as $accountId => $data) {
                    $content = $data['content'] ?? '';
                    if (! $mainContent && $content) {
                        $mainContent = $content;
                    }

                    $account = $ytSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if ($account) {
                        $contentsBySlug[$account->platform->slug] = $content;
                    }
                }

                $item = $ytSource->ytItems()->find($ytItemId);
                if (! $item) {
                    continue;
                }

                $post = Post::create([
                    'user_id' => $adminUser->id,
                    'content_fr' => $mainContent,
                    'platform_contents' => $contentsBySlug,
                    'link_url' => $item->url,
                    'source_type' => 'youtube',
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt,
                    'auto_translate' => true,
                ]);

                foreach ($platformContents as $accountId => $data) {
                    $account = $ytSource->socialAccounts->firstWhere('id', (int) $accountId);
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
                    $account = $ytSource->socialAccounts->firstWhere('id', (int) $accountId);
                    if (! $account) {
                        continue;
                    }

                    YtPost::create([
                        'yt_item_id' => $ytItemId,
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

    private function getLastPostDate(YtSource $ytSource): Carbon
    {
        $lastDate = Post::where('source_type', 'youtube')
            ->whereIn('status', ['scheduled', 'published'])
            ->whereHas('postPlatforms', function ($q) use ($ytSource) {
                $q->whereIn('social_account_id', $ytSource->socialAccounts->pluck('id'));
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
