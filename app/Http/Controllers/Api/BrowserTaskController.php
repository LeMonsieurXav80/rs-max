<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tâches exécutées par Companion dans le Chromium du VPS. */
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

    public function finish(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        $v = $r->validate([
            'status' => 'required|in:done,failed,uncertain',
            'result' => 'nullable|array',
            'error' => 'nullable|string|max:1000',
        ]);
        abort_unless($task->status === BrowserTask::RUNNING, 409, 'Tâche déjà terminée.');

        $task->update($v + ['finished_at' => now()]);

        // Chaque geste est journalisé, sauf les contrôles de session, trop fréquents pour être utiles.
        if (! in_array($task->kind, ['x.whoami', 'tiktok.whoami', 'session.apply'], true)) {
            BotActionLog::create([
                'social_account_id' => $task->social_account_id,
                'action_type' => $task->kind,
                'source' => 'browser',
                'target_uri' => (string) ($task->payload['url'] ?? ''),
                'target_text' => isset($task->payload['text']) ? mb_substr($task->payload['text'], 0, 2000) : null,
                'success' => $v['status'] === BrowserTask::DONE,
                'error' => $v['error'] ?? null,
                'performed_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
