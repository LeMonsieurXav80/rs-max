<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrowserTask;
use App\Models\PostPlatform;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Media\TikTokPostMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function checkpoint(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        $v = $r->validate(['result' => 'required|array']);
        if ($task->kind === 'tiktok.reply') {
            app(\App\Services\Browser\TikTokReplyReceipt::class)->checkpoint($task, $v['result']);
        } elseif ($task->kind === 'threads.agent.act') {
            app(\App\Services\Threads\ThreadsAgent::class)->checkpoint($task, $v['result']);
        } else {
            app(\App\Services\Agent\TikTokPhotoPublisher::class)->checkpoint($task, $v['result']);
        }

        return response()->json(['ok' => true]);
    }

    public function authorizeThreads(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        app(\App\Services\Threads\ThreadsAgent::class)->authorize($task);

        return response()->json(['ok' => true]);
    }

    public function authorizePhoto(Request $r, BrowserTask $task): JsonResponse
    {
        abort_unless($r->user()->isAdmin(), 403);
        abort_unless($task->status === 'running' && $task->kind === 'tiktok.photo.publish', 409);
        app(\App\Services\Agent\TikTokPhotoPublisher::class)->claim($task);

        return response()->json(['ok' => true]);
    }

    /** Réserve le seul clic final d'une diffusion demandée dans RS-Max. */
    public function authorizeTikTokPublication(Request $request, BrowserTask $task): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        DB::transaction(function () use ($task) {
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $payload = $task->payload;
            abort_unless($task->kind === 'tiktok.publish' && $task->status === BrowserTask::RUNNING
                && empty($payload['dry']) && empty($task->result['commit_authorized'])
                && isset($payload['deadline']) && now()->lt(Carbon::parse($payload['deadline']))
                && preg_match('/^post-platform:(\d+)$/', $task->idempotency_key ?? '', $match), 409);

            $delivery = PostPlatform::whereKey($match[1])->lockForUpdate()->first();
            abort_unless($delivery && $delivery->social_account_id === $task->social_account_id
                && $delivery->status === 'publishing' && $delivery->post?->status === 'publishing'
                && $delivery->socialAccount?->browser_profile === $task->profile, 409);
            $rules = app(TikTokPostMedia::class);
            try {
                $rules->type($payload['media'] ?? []);
                foreach ($payload['media'] as $media) {
                    abort_unless($rules->catalogFile($media['name'] ?? ''), 422, 'Média TikTok indisponible.');
                }
            } catch (BrowserException $e) {
                abort(422, $e->getMessage());
            }
            $task->update(['result' => ['commit_authorized' => true]]);
        });

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

        app(\App\Services\Agent\AutomationJournal::class)->append($task, $v['diagnostics'] ?? [], true);
        unset($v['diagnostics']);
        if ($task->kind === 'tiktok.reply') {
            app(\App\Services\Browser\TikTokReplyReceipt::class)->checkpoint($task, $v['result'] ?? []);
            $task->refresh();
            if (isset($task->result['submission'])) {
                $v['result']['submission'] = $task->result['submission'];
            }
        }
        if (in_array($task->kind, ['tiktok.photo.publish', 'threads.agent.act', 'tiktok.reply', 'tiktok.publish'], true)) {
            $v['result'] = array_replace($task->result ?? [], $v['result'] ?? []);
        }
        $task->update($v + ['finished_at' => now()]);
        app(\App\Services\Threads\ThreadsAgent::class)->finish($task);
        app(\App\Services\Agent\TikTokPhotoPublisher::class)->finish($task);
        app(\App\Services\Agent\XEngagementService::class)->finish($task);
        app(\App\Services\Agent\AccountActionBudget::class)->finish($task);

        return response()->json(['ok' => true]);
    }
}
