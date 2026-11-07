<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tâches exécutées par Companion dans le Chromium du NAS. */
class BrowserTaskController extends Controller
{
    public function next(Request $r, BrowserTaskQueue $queue): JsonResponse
    {
        $v = $r->validate(['profile' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,40}$/']]);
        abort_unless($r->user()->isAdmin(), 403);

        $task = $queue->claim($v['profile']);

        if (! $task) {
            return response()->json(['id' => null]);
        }

        // Plusieurs comptes X partagent le Chromium : Companion vérifie celui qui est connecté avant d'agir.
        $accountId = str_starts_with($task->kind, 'x.') ? (string) $task->socialAccount->platform_account_id : null;

        return response()->json(['id' => $task->id, 'kind' => $task->kind, 'payload' => $task->payload, 'account_id' => $accountId]);
    }

    /** Session du compte d'une tâche en cours, pour que Companion change de compte avant d'agir. */
    public function session(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        abort_unless($task->status === BrowserTask::RUNNING, 409, 'Tâche déjà terminée.');
        $cookies = $task->socialAccount->browser_session;
        abort_if(empty($cookies), 422, 'Aucune session enregistrée pour ce compte.');

        return response()->json(['cookies' => $cookies]);
    }

    public function progress(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        abort_unless($task->status === BrowserTask::RUNNING, 409, 'Tâche déjà terminée.');
        $v = $r->validate(['steps' => 'required|array|min:1|max:100', 'steps.*.stage' => 'required|string|max:100', 'steps.*.id' => 'sometimes|string|max:80', 'steps.*.elapsed_ms' => 'sometimes|integer|min:0|max:3600000', 'steps.*.details' => 'sometimes|array|max:30']);
        app(\App\Services\Agent\AutomationJournal::class)->append($task, $v['steps']);

        return response()->json(['ok' => true]);
    }

    public function finish(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        $v = $r->validate([
            'status' => 'required|in:done,failed,uncertain',
            'result' => 'nullable|array',
            'diagnostics' => 'sometimes|array|max:100',
            'error' => 'nullable|string|max:1000',
        ]);
        abort_unless($task->status === BrowserTask::RUNNING, 409, 'Tâche déjà terminée.');

        app(\App\Services\Agent\AutomationJournal::class)->append($task, $v['diagnostics'] ?? []);
        unset($v['diagnostics']);
        $task->update($v + ['finished_at' => now()]);
        app(\App\Services\Agent\XEngagementService::class)->finish($task);
        app(\App\Services\Agent\AccountActionBudget::class)->finish($task);

        return response()->json(['ok' => true]);
    }
}
