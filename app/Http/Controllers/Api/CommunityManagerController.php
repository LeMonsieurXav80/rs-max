<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\TikTokEngagement;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\TikTokVideoCommentGenerator;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Interface courte du robot : aucun HTML ni prompt persona ne quitte RS-Max. */
class CommunityManagerController extends Controller
{
    public function __construct(private CommunityManagerWindow $window, private AgentCatalog $catalog) {}

    private function account(Request $request, int $id): SocialAccount
    {
        abort_unless($request->user()->isManager(), 403);
        $account = $request->user()->connectedSocialAccounts()->with(['platform', 'persona'])->find($id);
        abort_unless($account, 403, 'Compte non attribué à Community Manager.');

        return $account;
    }

    private function duringDay(Request $request): void
    {
        abort_unless($this->window->isOpen(), 423, 'Community Manager travaille de 09 h à 17 h, heure de Lisbonne.');
        $request->attributes->set('community_manager', true);
    }

    public function accounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $accounts = $request->user()->connectedSocialAccounts()->with(['platform', 'persona'])->get();

        return response()->json(['daytime' => $this->window->isOpen(), 'window' => $this->window->specification(),
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform->slug,
                'session' => $a->browser_session_status, 'persona' => $a->persona?->is_active ? $a->persona->id : null,
                'actions' => $this->catalog->forAccount($a)->where('enabled', true)->where('status', 'ready')
                    ->map(fn ($s) => ['kind' => $s['kind'], 'left' => $s['left']])])->values()]);
    }

    public function read(Request $request, TikTokFeedController $feed, BrowserTaskQueue $browser): JsonResponse
    {
        $this->duringDay($request);
        $id = $request->validate(['account_id' => 'required|integer|min:1'])['account_id'];
        $account = $this->account($request, $id);
        $response = $feed->read($request, $id, $browser);
        $data = $response->getData(true);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        if (isset($data['items'])) {
            $items = array_map(function ($video) use ($account) {
                $snapshot = (string) Str::uuid();
                // Un like de vidéo n'a pas besoin de charger sa discussion.
                Cache::put('tiktok-video:'.$snapshot, ['account_id' => $account->id, 'video' => $video, 'comments' => [], 'complete' => false], now()->addHour());

                return ['url' => $video['url'], 'caption' => Str::limit($video['caption'] ?? '', 180),
                    'liked' => $video['liked'] ?? null, 'snapshot_id' => $snapshot];
            }, $data['items']);

            return response()->json(['items' => $items, 'left' => $data['left']]);
        }

        return response()->json($this->compactSnapshot($data) + ['left' => $data['left']]);
    }

    public function snapshot(Request $request, string $id): JsonResponse
    {
        $data = Cache::get('tiktok-video:'.$id);
        abort_unless($data, 404, 'Lecture expirée.');
        $this->account($request, $data['account_id']);

        return response()->json($this->compactSnapshot($data + ['snapshot_id' => $id]));
    }

    private function compactSnapshot(array $data): array
    {
        return ['snapshot_id' => $data['snapshot_id'], 'url' => $data['video']['url'],
            'caption' => Str::limit($data['video']['caption'] ?? '', 180), 'complete' => $data['complete'],
            'accessible_complete' => $data['accessible_complete'] ?? $data['complete'],
            'observed' => $data['observed'] ?? count($data['comments']), 'expected' => $data['expected'] ?? null,
            'at_bottom' => $data['progress']['at_bottom'] ?? null,
            'sample' => array_map(fn ($c) => ['key' => $c['key'], 'text' => Str::limit($c['text'] ?? '', 120),
                'liked' => $c['liked'] ?? null], array_slice($data['comments'], 0, 3))];
    }

    public function prepare(Request $request, TikTokFeedController $feed, TikTokVideoCommentGenerator $generator): JsonResponse
    {
        $this->duringDay($request);
        $id = $request->validate(['account_id' => 'required|integer|min:1'])['account_id'];
        $this->account($request, $id);
        $response = $feed->prepare($request, $id, $generator);
        $id = $response->getData(true)['id'] ?? null;
        $row = $id ? TikTokEngagement::find($id) : null;
        if ($row?->status === 'prepared') {
            $row->update(['payload' => $row->payload + ['activity_window' => $this->window->specification()]]);
        }

        return $response;
    }

    public function execute(Request $request, TikTokEngagement $engagement, TikTokFeedController $feed): JsonResponse
    {
        $this->duringDay($request);
        $this->account($request, $engagement->social_account_id);
        abort_unless(isset($engagement->payload['activity_window']), 409, 'Action préparée hors Community Manager.');
        abort_unless($this->window->isOpen($engagement->payload['activity_window']), 409, 'Action préparée un autre jour.');

        return $feed->send($request, $engagement);
    }

    public function result(Request $request, TikTokEngagement $engagement): JsonResponse
    {
        $this->account($request, $engagement->social_account_id);

        return response()->json(['id' => $engagement->id, 'action' => $engagement->action, 'status' => $engagement->status,
            'confirmed' => $engagement->status === 'confirmed', 'error' => $engagement->error]);
    }

    public function uploadInspect(Request $request, BrowserTaskQueue $browser): JsonResponse
    {
        $this->duringDay($request);
        $id = $request->validate(['account_id' => 'required|integer|min:1'])['account_id'];
        $account = $this->account($request, $id);
        abort_unless($account->usesBrowser() && $account->platform->slug === 'tiktok' && $account->browser_session_status === 'connected', 422);

        return response()->json($browser->run($account, 'tiktok.upload.inspect', [
            'url' => 'https://www.tiktok.com/tiktokstudio/upload', 'username' => $account->browser_username,
        ], 90));
    }
}
