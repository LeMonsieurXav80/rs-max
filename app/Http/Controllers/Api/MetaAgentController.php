<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\MetaBrowserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class MetaAgentController extends Controller
{
    private function account(Request $r, ?int $id, ?string $platform): SocialAccount
    {
        $q = $r->user()->connectedSocialAccounts()->with(['platform', 'persona']);
        if ($id !== null) {
            abort_unless($r->user()->isManager(), 403);
            $a = $q->findOrFail($id);
        } else {
            $r->validate(['platform_account_id' => 'required|string|regex:/^\d{1,30}$/']);
            $this->token($r);
            $a = $q->whereHas('platform', fn ($q) => $q->where('slug', $platform))
                ->where('platform_account_id', $r->input('platform_account_id'))->firstOrFail();
        }
        abort_unless(in_array($a->platform->slug, ['facebook', 'instagram'], true) && $a->usesBrowser(), 422);

        return $a;
    }

    private function token(Request $r): int
    {
        $token = $r->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403, 'Jeton Companion requis.');

        return $token->id;
    }

    public static function ownsLocal(Request $r, BrowserTask $task): bool
    {
        $token = $r->user()->currentAccessToken();

        return MetaBrowserAgent::handles($task) && $task->profile === MetaBrowserAgent::LOCAL
            && $token instanceof PersonalAccessToken && $token->id === ($task->payload['local_token_id'] ?? null)
            && $r->user()->connectedSocialAccounts()->whereKey($task->social_account_id)->exists();
    }

    private function access(Request $r, BrowserTask $task): void
    {
        abort_unless(MetaBrowserAgent::handles($task), 404);
        abort_unless(self::ownsLocal($r, $task) || ($r->user()->isManager()
            && $r->user()->connectedSocialAccounts()->whereKey($task->social_account_id)->exists()), 403);
    }

    public function read(Request $r, MetaBrowserAgent $agent, ?int $id = null, ?string $platform = null)
    {
        $a = $this->account($r, $id, $platform);
        $d = $r->validate(['action' => 'required|in:group.read,search.read,thread.read,profile.read,followback.read',
            'url' => 'required_if:action,group.read,thread.read|string|max:500',
            'hashtag' => 'required_if:action,search.read|string|max:101',
            'handle' => ['required_if:action,profile.read,followback.read', 'string', 'regex:/^@?[a-zA-Z0-9_.]{1,30}$/'],
            'limit' => 'sometimes|integer|min:1|max:30']);
        $task = $agent->read($a, $d, $id === null ? $this->token($r) : null);

        return response()->json(['task_id' => $task->id, 'status' => $task->status], 202);
    }

    public function localRead(Request $r, MetaBrowserAgent $agent, string $platform)
    {
        return $this->read($r, $agent, null, $platform);
    }

    public function localSubmit(Request $r, MetaBrowserAgent $agent, string $platform)
    {
        return $this->submit($r, $agent, null, $platform);
    }

    public function submit(Request $r, MetaBrowserAgent $agent, ?int $id = null, ?string $platform = null)
    {
        $a = $this->account($r, $id, $platform);
        $d = $r->validate(['read_task_id' => 'required|integer', 'action' => 'required|in:comment,comment.like,like,follow,unfollow',
            'target_id' => 'required_if:action,comment.like|string|max:150', 'text' => 'required_if:action,comment|string|max:500',
            'persona_hash' => 'required_if:action,comment|string|size:64', 'dry_run' => 'sometimes|boolean', 'request_key' => 'required_if:dry_run,false|uuid']);
        $read = BrowserTask::findOrFail($d['read_task_id']);
        if ($id === null) {
            abort_unless(self::ownsLocal($r, $read), 403);
        }

        return response()->json($agent->submit($a, $read, $d, $id === null ? $this->token($r) : null), ($d['dry_run'] ?? true) ? 200 : 202);
    }

    public function context(Request $r, BrowserTask $task, MetaBrowserAgent $agent)
    {
        $this->access($r, $task);

        return response()->json($agent->context($task->socialAccount, $task));
    }

    public function draft(Request $r, BrowserTask $task, MetaBrowserAgent $agent)
    {
        $this->access($r, $task);
        $account = $task->socialAccount;
        abort_unless($account->platform->slug === 'facebook', 422);
        $context = $agent->context($account, $task);
        $item = new \App\Models\InboxItem(['type' => 'comment', 'content' => $context['target']['text'],
            'author_username' => $context['target']['author'], 'social_account_id' => $account->id]);
        $text = app(\App\Services\Inbox\InboxReplyGenerator::class)->generate($item, $account, true, 500,
            json_encode($context, JSON_UNESCAPED_UNICODE), 'Facebook', 'de la publication du groupe et de ses commentaires', extraInstructions: $context['instruction']);
        abort_unless(filled($text) && mb_strlen($text) <= 500, 503, 'Brouillon indisponible.');

        return response()->json(['text' => trim($text), 'persona_hash' => $context['persona_hash']]);
    }

    public function status(Request $r, BrowserTask $task)
    {
        $this->access($r, $task);

        return response()->json(['task_id' => $task->id, 'status' => $task->status, 'result' => $task->result, 'error' => $task->error,
            'cadence' => app(\App\Services\Agent\AccountActionBudget::class)->usage($task->socialAccount)]);
    }

    public function claim(Request $r, BrowserTask $task)
    {
        abort_unless(self::ownsLocal($r, $task), 403);

        return DB::transaction(function () use ($task) {
            SocialAccount::whereKey($task->social_account_id)->lockForUpdate()->firstOrFail();
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->status === 'pending', 409, 'Tâche déjà prise : aucun renvoi.');
            foreach (BrowserTask::where('social_account_id', $task->social_account_id)->where('profile', MetaBrowserAgent::LOCAL)
                ->where('status', BrowserTask::RUNNING)->where('claimed_at', '<', now()->subSeconds(\App\Services\Browser\BrowserTaskQueue::ORPHAN_AFTER))
                ->lockForUpdate()->get() as $orphan) {
                $orphan->update(['status' => BrowserTask::readsOnly($orphan->kind) ? BrowserTask::FAILED : BrowserTask::UNCERTAIN,
                    'finished_at' => now(), 'error' => 'Lancement local interrompu depuis plus de 30 minutes : aucun renvoi.']);
                app(\App\Services\Agent\AccountActionBudget::class)->finish($orphan);
            }
            abort_if(BrowserTask::where('social_account_id', $task->social_account_id)->where('profile', MetaBrowserAgent::LOCAL)->where('status', 'running')->exists(), 409, 'Une tâche locale est déjà en cours.');
            if (! app(\App\Services\Browser\BrowserTaskQueue::class)->start($task)) {
                return response()->json(['message' => $task->error ?? 'Prise refusée.'], 409);
            }

            return response()->json(['id' => $task->id, 'kind' => $task->kind, 'account_id' => (string) $task->socialAccount->platform_account_id, 'payload' => $task->payload]);
        });
    }

    public function authorize(Request $r, BrowserTask $task, MetaBrowserAgent $agent)
    {
        abort_unless($r->user()->isAdmin() || self::ownsLocal($r, $task), 403);
        $agent->authorize($task);

        return response()->json(['ok' => true]);
    }

    public function reconcile(Request $r, BrowserTask $task, MetaBrowserAgent $agent)
    {
        $this->access($r, $task);
        $r->validate(['read_task_id' => 'required|integer']);
        $read = BrowserTask::findOrFail($r->input('read_task_id'));
        $this->access($r, $read);

        return response()->json($agent->reconcile($task, $read));
    }
}
