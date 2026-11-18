<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Services\Agent\Feed\LocalFeedRun;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** « Engager mon fil » lancé depuis Companion sur le Mac. Voir docs/flux-engagement-scripte.md. */
class LocalFeedRunController extends Controller
{
    private function identity(Request $r): array
    {
        $user = $r->user();
        abort_unless($user->isManager() || ($r->input('platform') === 'x' && LocalFeedRun::xTokenId($user) !== null),
            403, 'Ce jeton Companion ne peut pas engager le fil de ce réseau.');

        return $r->validate(['platform' => 'required|in:x,threads', 'username' => ['required', 'string', 'regex:/^@?[A-Za-z0-9_.]{1,50}$/']]);
    }

    public function start(Request $r, LocalFeedRun $runs): JsonResponse
    {
        $who = $this->identity($r);
        $data = $r->validate(['items' => 'present|array|max:'.LocalFeedRun::MAX_ITEMS, 'items.*' => 'array', 'commit' => 'sometimes|boolean']);

        return response()->json($runs->start($runs->account($r->user(), $who['platform'], $who['username']), $data['items'],
            $r->boolean('commit'), $r->user()->isManager() ? null : LocalFeedRun::xTokenId($r->user())));
    }

    public function next(Request $r, string $run, LocalFeedRun $runs): JsonResponse
    {
        $who = $this->identity($r);

        $r->validate(['stop' => 'sometimes|boolean']);

        return response()->json($runs->next($runs->account($r->user(), $who['platform'], $who['username']), $run, $r->boolean('stop'),
            $r->user()->isManager() ? null : LocalFeedRun::xTokenId($r->user())));
    }

    /** Prend une tâche du passage local par son identifiant, avec les contrôles de prise habituels. */
    public function claim(Request $r, BrowserTask $task, BrowserTaskQueue $queue): JsonResponse
    {
        abort_unless($r->user()->isAdmin() || LocalFeedRun::canCompleteXTask($r->user(), $task),
            403, 'Ce jeton Companion ne peut pas prendre cette tâche.');
        abort_unless($task->profile === LocalFeedRun::PROFILE
            && $r->user()->connectedSocialAccounts()->whereKey($task->social_account_id)->exists(), 404);
        $started = DB::transaction(function () use ($task, $queue) {
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->status === BrowserTask::PENDING, 409, 'Tâche déjà prise ou terminée : aucun renvoi.');

            return $queue->start($task) ? $task->fresh() : null;
        });
        abort_unless($started, 409, $task->fresh()->error ?: 'Tâche refusée à la prise.');
        $accountId = str_starts_with($started->kind, 'x.') ? (string) $started->socialAccount->platform_account_id : null;

        return response()->json(['id' => $started->id, 'kind' => $started->kind, 'payload' => $started->payload, 'account_id' => $accountId]);
    }
}
