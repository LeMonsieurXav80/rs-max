<?php

namespace App\Jobs;

use App\Models\BrowserTask;
use App\Models\XEngagement;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public static function verifiable(BrowserTask $task): bool
    {
        return $task->kind === 'x.agent.act' && ($task->payload['action'] ?? null) === 'reply'
            && $task->status === BrowserTask::UNCERTAIN && $task->claimed_at && $task->finished_at
            && ($task->result['platform_limit']['source'] ?? null) !== 'platform_ui';
    }

    public static function candidateId(BrowserTask $task): ?string
    {
        $id = $task->result['id'] ?? $task->result['reply_id'] ?? null;
        if (! self::verifiable($task) || ! is_string($id) || $id === ($task->payload['target']['id'] ?? null)) {
            return null;
        }

        return self::duringAttempt($task, $id) ? $id : null;
    }

    private static function duringAttempt(BrowserTask $task, string $id): bool
    {
        return (bool) CommentFreshness::xStatusDate($id)?->betweenIncluded($task->claimed_at->copy()->subSeconds(5), $task->finished_at->copy()->addMinute());
    }

    /**
     * L’identifiant capturé après le clic peut désigner un ancien post du compte apparu dans la page.
     * On relit alors la cible : une seule réponse du compte, au texte exact et datée de la tentative, la désigne.
     */
    private function locateUnderParent(BrowserTaskQueue $browser, $account, BrowserTask $task, string $username, string $text, ?array $window): ?string
    {
        $parent = $task->payload['target'] ?? [];
        if (! ctype_digit((string) ($parent['id'] ?? '')) || ! preg_match('~^https://x\.com/[A-Za-z0-9_]{1,15}/status/[0-9]+$~', (string) ($parent['url'] ?? ''))) {
            return null;
        }
        $payload = ['url' => $parent['url'], 'action' => 'thread.read', 'username' => $username, 'target_id' => (string) $parent['id']];
        if ($window !== null) {
            $payload['activity_window'] = $window;
        }
        try {
            $read = $browser->run($account, 'x.agent.read', $payload, 100);
        } catch (BrowserException $e) {
            Log::notice('X : relecture de la cible impossible', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }
        $ids = collect($read['visible_replies'] ?? [])
            ->filter(fn ($p) => strtolower($p['author'] ?? '') === strtolower($username)
                && $this->normalize($p['text'] ?? null) === $text && ctype_digit((string) ($p['id'] ?? ''))
                && self::duringAttempt($task, (string) $p['id']))
            ->pluck('id')->unique()->values();

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    public function handle(BrowserTaskQueue $browser, AccountActionBudget $budget): void
    {
        $engagement = XEngagement::with(['browserTask', 'socialAccount.platform'])->find($this->engagementId);
        $task = $engagement?->browserTask;
        if (! $task || $engagement->status !== 'uncertain' || $engagement->action !== 'reply'
            || $task->social_account_id !== $engagement->social_account_id || ! self::verifiable($task)) {
            return;
        }
        $id = self::candidateId($task);

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
        $id ??= $this->locateUnderParent($browser, $account, $task, $username, $text, $window);
        if (! $id) {
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
