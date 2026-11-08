<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Services\Threads\ThreadsAgent;
use Illuminate\Http\Request;

class ThreadsAgentController extends Controller
{
    private function account(Request $r, int $id)
    {
        abort_unless($r->user()->isManager(), 403);
        $a = $r->user()->connectedSocialAccounts()->with(['platform', 'persona'])->findOrFail($id);
        abort_unless($a->platform->slug === 'threads', 422);

        return $a;
    }

    public function read(Request $r, int $id, ThreadsAgent $agent)
    {
        $d = $r->validate(['action' => 'required|in:feed.read,search.read,thread.read,profile.read', 'query' => 'required_if:action,search.read|string|max:150', 'url' => 'required_if:action,thread.read|url|max:500', 'handle' => ['required_if:action,profile.read', 'string', 'regex:/^@?[a-zA-Z0-9_.]{1,50}$/'], 'limit' => 'sometimes|integer|min:1|max:20']);

        return response()->json($agent->read($this->account($r, $id), $d));
    }

    public function context(Request $r, int $id, ThreadsAgent $agent)
    {
        $r->validate(['snapshot_id' => 'required|uuid']);

        return response()->json($agent->context($this->account($r, $id), $r->input('snapshot_id')));
    }

    public function submit(Request $r, int $id, ThreadsAgent $agent)
    {
        $d = $r->validate(['action' => 'required|in:like,comment', 'snapshot_id' => 'required|uuid', 'target_id' => 'sometimes|string|max:100', 'context_id' => 'nullable|uuid', 'text' => 'required_if:action,comment|string|max:500', 'dry_run' => 'sometimes|boolean', 'request_key' => 'required_if:dry_run,false|nullable|uuid']);
        abort_if(isset($d['text']) && strlen(mb_convert_encoding($d['text'], 'UTF-16LE', 'UTF-8')) / 2 > 500, 422, 'Commentaire trop long.');

        return response()->json($agent->submit($this->account($r, $id), $d), ($d['dry_run'] ?? true) ? 200 : 202);
    }

    public function verify(Request $r, BrowserTask $task, ThreadsAgent $agent)
    {
        $this->account($r, $task->social_account_id);
        $r->validate(['dry_run' => 'sometimes|boolean']);

        return response()->json($agent->verify($task, $r->boolean('dry_run', true)));
    }

    public function status(Request $r, BrowserTask $task, ThreadsAgent $agent)
    {
        $this->account($r, $task->social_account_id);
        abort_unless($task->kind === 'threads.agent.act', 404);

        return response()->json($agent->state($task));
    }
}
