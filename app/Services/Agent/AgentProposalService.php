<?php

namespace App\Services\Agent;

use App\Jobs\SendAgentProposal;
use App\Models\AgentProposal;
use App\Models\BotActionLog;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Inbox\InboxReplyService;

/** Prépare les réponses, puis les envoie une fois validées par un humain. */
class AgentProposalService
{
    private const ACTION = 'reply';

    private const SEND_TIMEOUT = 120;

    public function __construct(
        private AgentCatalog $catalog,
        private ConversationCollector $collector,
        private AgentReplyGenerator $generator,
        private InboxReplyService $replies,
        private BrowserTaskQueue $queue,
    ) {}

    /** @return array{created: int, tokens: int} */
    public function prepare(SocialAccount $account, int $limit): array
    {
        $state = $this->catalog->forAccount($account)->get(self::ACTION);
        $pending = AgentProposal::where('social_account_id', $account->id)->where('status', AgentProposal::PENDING)->count();
        $room = min($limit, config('agent.batch_limit'), max(0, ($state['left'] ?? 0) - $pending));
        if (! ($state['enabled'] ?? false) || $room < 1) {
            return ['created' => 0, 'tokens' => 0];
        }

        $conversations = $this->collector->collect($account, $room);
        if ($conversations === []) {
            return ['created' => 0, 'tokens' => 0];
        }

        $result = $this->generator->generate($account, $conversations);
        $created = 0;
        foreach ($conversations as $i => $conversation) {
            $text = $result['replies'][$i + 1] ?? null;
            if ($text === null) {
                continue;
            }
            AgentProposal::firstOrCreate(['social_account_id' => $account->id, 'target_key' => $conversation['key']], [
                'inbox_item_id' => $conversation['inbox_item_id'], 'persona_id' => $account->persona_id, 'target' => $conversation['target'],
                'author' => $conversation['author'], 'conversation' => $conversation['thread'], 'reply_text' => $text,
            ]);
            $created++;
        }

        $this->log($account, 'reply.prepare', true, null, ['conversations' => count($conversations), 'created' => $created, 'tokens' => $result['tokens']]);

        return ['created' => $created, 'tokens' => $result['tokens']];
    }

    /** Seule une proposition encore en attente part : un double clic n'envoie rien de plus. */
    public function approve(AgentProposal $proposal, string $text, User $user): bool
    {
        $claimed = AgentProposal::whereKey($proposal->id)->where('status', AgentProposal::PENDING)->update([
            'status' => AgentProposal::APPROVED, 'reply_text' => $text, 'decided_by' => $user->id, 'decided_at' => now(), 'error' => null,
        ]);

        if ($claimed) {
            SendAgentProposal::dispatch($proposal->id);
        }

        return (bool) $claimed;
    }

    public function reject(AgentProposal $proposal, User $user): bool
    {
        return (bool) AgentProposal::whereKey($proposal->id)->where('status', AgentProposal::PENDING)->update([
            'status' => AgentProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now(),
        ]);
    }

    /** Un seul essai : une réponse non confirmée a pu partir, elle reste à contrôler. */
    public function send(AgentProposal $proposal): void
    {
        if (! AgentProposal::whereKey($proposal->id)->where('status', AgentProposal::APPROVED)->update(['status' => AgentProposal::SENDING])) {
            return;
        }

        $proposal->refresh();
        $account = $proposal->socialAccount()->with('platform')->first();
        $state = $this->catalog->forAccount($account)->get(self::ACTION);

        if (($state['left'] ?? 0) < 1) {
            $proposal->update(['status' => AgentProposal::PENDING, 'error' => 'Quota du jour atteint : à revalider demain.']);

            return;
        }

        [$status, $error] = $this->deliver($account, $proposal);
        $proposal->update(['status' => $status, 'error' => $error, 'sent_at' => $status === AgentProposal::SENT ? now() : null]);
        $this->log($account, self::ACTION, $status === AgentProposal::SENT, $proposal, [], $error);
    }

    /** @return array{0: string, 1: ?string} */
    private function deliver(SocialAccount $account, AgentProposal $proposal): array
    {
        if ($proposal->inbox_item_id) {
            $item = InboxItem::find($proposal->inbox_item_id);
            $result = $item ? $this->replies->send($item, $proposal->reply_text) : ['success' => false, 'error' => 'Message disparu de la boîte de réception.'];

            return $result['success'] ? [AgentProposal::SENT, null] : [AgentProposal::FAILED, $result['error'] ?? 'Envoi refusé.'];
        }

        try {
            $this->queue->run($account, 'tiktok.reply', [
                'url' => 'https://www.tiktok.com/tiktokstudio/comment', 'username' => $account->browser_username,
                'target' => $proposal->target, 'text' => $proposal->reply_text,
            ], self::SEND_TIMEOUT);
        } catch (BrowserException $e) {
            return [str_contains($e->getMessage(), 'vérifier') ? AgentProposal::UNCERTAIN : AgentProposal::FAILED, $e->getMessage()];
        }

        return [AgentProposal::SENT, null];
    }

    private function log(SocialAccount $account, string $action, bool $success, ?AgentProposal $proposal = null, array $metadata = [], ?string $error = null): void
    {
        BotActionLog::create([
            'social_account_id' => $account->id, 'action_type' => $this->catalog->logType($action), 'source' => AgentCatalog::SOURCE,
            'target_uri' => (string) ($proposal?->target_key ?? ''), 'target_author' => $proposal?->author, 'target_text' => $proposal?->reply_text,
            'success' => $success, 'error' => $error, 'metadata' => $metadata, 'performed_at' => now(),
        ]);
    }
}
