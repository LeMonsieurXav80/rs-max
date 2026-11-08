<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Services\Agent\TikTokPhotoPublisher;
use Illuminate\Http\Request;

class TikTokPhotoController extends Controller
{
    public function folders(Request $r)
    {
        abort_unless($r->user()->isManager(), 403);
        $folders = \App\Models\MediaFolder::ordered()->get()->filter(fn ($f) => ! $f->isEffectivelyPrivate())->map(fn ($f) => $f->only(['id', 'slug', 'name', 'parent_id']))->values();

        return response()->json(['folders' => $folders]);
    }

    public function mediaSearch(Request $r)
    {
        abort_unless($r->user()->isManager(), 403);
        $r->validate(['folder' => 'required|string']);
        $folder = \App\Models\MediaFolder::where('slug', $r->input('folder'))->firstOrFail();
        abort_if($folder->isEffectivelyPrivate(), 403);

        return app(MediaApiController::class)->search($r);
    }

    private function account(Request $r, int $id)
    {
        abort_unless($r->user()->isManager(), 403);
        $account = $r->user()->connectedSocialAccounts()->with('platform')->findOrFail($id);
        abort_unless($account->platform->slug === 'tiktok', 422);

        return $account;
    }

    public function store(Request $r, int $id, TikTokPhotoPublisher $publisher)
    {
        $a = $this->account($r, $id);
        if (! $r->exists('dry_run')) {
            $r->merge(['dry_run' => true]);
        }
        $data = $r->validate(['media_ids' => 'required|array|min:1|max:35', 'media_ids.*' => 'required|integer|min:1|distinct',
            'title' => 'nullable|string|max:90', 'caption' => 'required|string|max:4000', 'visibility' => 'required|in:public,private',
            'dry_run' => 'boolean', 'rehearse' => 'sometimes|boolean', 'request_key' => 'required_if:dry_run,false|nullable|uuid']);
        if (! $data['dry_run']) {
            abort_unless(app(\App\Services\Agent\CommunityManagerWindow::class)->isOpen(), 423, 'Publication autorisée de 06 h à 22 h à Lisbonne.');
        }
        abort_if(strlen(mb_convert_encoding($data['caption'], 'UTF-16LE', 'UTF-8')) / 2 > 4000 || strlen(mb_convert_encoding($data['title'] ?? '', 'UTF-16LE', 'UTF-8')) / 2 > 90, 422, 'Texte trop long pour Studio.');
        $result = $publisher->submit($r->user(), $a, $data);

        return response()->json($result, ($data['dry_run'] ?? true) ? 200 : 202);
    }

    public function show(Request $r, BrowserTask $task, TikTokPhotoPublisher $publisher)
    {
        $this->account($r, $task->social_account_id);
        abort_unless($task->kind === 'tiktok.photo.publish', 404);

        return response()->json($publisher->state($task));
    }

    public function verify(Request $r, BrowserTask $task, TikTokPhotoPublisher $publisher)
    {
        $this->account($r, $task->social_account_id);
        $r->validate(['dry_run' => 'sometimes|boolean']);
        abort_unless(app(\App\Services\Agent\CommunityManagerWindow::class)->isOpen(), 423);

        return response()->json($publisher->verify($task, $r->boolean('dry_run', true)));
    }

    public function form(Request $r)
    {
        abort_unless($r->user()->isManager(), 403);
        $ids = array_filter(explode(',', $r->query('media_ids', '')), 'strlen');
        validator(['ids' => $ids], ['ids' => 'required|array|min:1|max:35', 'ids.*' => 'integer|min:1|distinct'])->validate();
        $media = app(TikTokPhotoPublisher::class)->media(array_map('intval', $ids));
        $accounts = $r->user()->connectedSocialAccounts()->whereRelation('platform', 'slug', 'tiktok')->get();

        return view('media.tiktok-photos', compact('media', 'accounts'));
    }
}
