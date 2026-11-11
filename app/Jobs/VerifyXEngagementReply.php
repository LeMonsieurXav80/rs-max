<?php

namespace App\Jobs;

use App\Models\BrowserTask;
use App\Models\XEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyXEngagementReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $engagementId) {}

    public function uniqueId(): string
    {
        return (string) $this->engagementId;
    }

    public static function candidateId(BrowserTask $task): ?string
    {
        $id = $task->result['id'] ?? $task->result['reply_id'] ?? null;
        if ($task->kind !== 'x.agent.act' || ($task->payload['action'] ?? null) !== 'reply'
            || $task->status !== BrowserTask::UNCERTAIN || ! $task->claimed_at || ! $task->finished_at
            || ($task->result['platform_limit']['source'] ?? null) === 'platform_ui'
            || ! is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! ctype_digit($id) || $id === ($task->payload['target']['id'] ?? null)) {
            return null;
        }

        $postedAt = Carbon::createFromTimestampMs(((int) $id >> 22) + 1288834974657, 'UTC');

        return $postedAt->betweenIncluded($task->claimed_at->copy()->subSeconds(5), $task->finished_at->copy()->addMinute()) ? $id : null;
    }

    public function handle(BrowserTaskQueue $browser, AccountActionBudget $budget): void
    {
        $engagement = XEngagement::with(['browserTask', 'socialAccount.platform'])->find($this->engagementId);
        $task = $engagement?->browserTask;
        if (! $task || $engagement->status !== 'uncertain' || $engagement->action !== 'reply'
            || $task->social_account_id !== $engagement->social_account_id
            || ! ($id = self::candidateId($task))) {
            return;
        }

        $account = $engagement->socialAccount;
        $username = $task->payload['username'] ?? '';
        $parentId = $task->payload['target']['id'] ?? '';
        $text = $this->normalize($task->payload['text'] ?? null);
        $window = $task->payload['activity_window'] ?? null;
        if ($account->platform->slug !== 'twitter' || ! $account->usesBrowser() || blank($account->browser_profile)
            || $account->browser_session_status !== 'connected' || $account->browser_username !== $username
            || ! preg_match('/^[a-zA-Z0-9_]{1,15}$/D', $username) || ! ctype_digit($parentId) || $text === ''
            || ($window !== null && ! app(CommunityManagerWindow::class)->isOpen($window))) {
            return;
        }

        $url = 'https://x.com/'.$username.'/status/'.$id;
        $payload = ['url' => $url, 'action' => 'thread.read', 'username' => $username, 'target_id' => $id,
            'target' => ['reply_parent_id' => $parentId]];
        if ($window !== null) {
            $payload['activity_window'] = $window;
        }

        try {
            $read = $browser->run($account, 'x.agent.read', $payload, 100);
        } catch (BrowserException $e) {
            Log::notice('X : relecture différée impossible', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return;
        }

        $target = $read['target'] ?? [];
        $conversation = $read['conversation'] ?? [];
        if (! is_array($conversation) || ($read['context_complete'] ?? false) !== true
            || ($target['id'] ?? null) !== $id || strtolower($target['author'] ?? '') !== strtolower($username)
            || strtolower($target['url'] ?? '') !== strtolower($url)
            || $this->normalize($target['text'] ?? null) !== $text
            || ($target['reply_parent_id'] ?? $parentId) !== $parentId
            || ($conversation[count($conversation) - 2]['id'] ?? null) !== $parentId
            || ($conversation[count($conversation) - 1]['id'] ?? null) !== $id) {
            return;
        }

        DB::transaction(function () use ($engagement, $task, $id, $url, $parentId, $budget) {
            $current = BrowserTask::whereKey($task->id)->where('status', BrowserTask::UNCERTAIN)->lockForUpdate()->first();
            $row = XEngagement::whereKey($engagement->id)->lockForUpdate()->first();
            if (! $current || ! $row || $row->browser_task_id !== $task->id || $row->status !== 'uncertain'
                || $current->result !== $task->result || $row->result !== $task->result
                || $current->payload !== $task->payload) {
                return;
            }

            $proof = array_replace($current->result, [
                'confirmed' => true, 'proof' => 'after_reload', 'reply_id' => $id, 'reply_url' => $url,
                'parent_id' => $parentId, 'verification_source' => 'fresh_thread_read',
                'observed_at' => now()->toIso8601String(), 'original_error' => $current->error,
            ]);
            $current->update(['status' => BrowserTask::DONE, 'result' => $proof, 'error' => null]);
            $row->update(['status' => 'confirmed', 'result' => $proof, 'error' => null]);
            $budget->finish($current);
        }, 3);
    }

    private function normalize(mixed $text): string
    {
        return is_string($text) ? trim(preg_replace('/\s+/u', ' ', $text) ?? '') : '';
    }
}
