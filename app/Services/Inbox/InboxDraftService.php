<?php

namespace App\Services\Inbox;

use App\Models\BotActionLog;
use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommentFreshness;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/** Rédige les réponses : elles restent en brouillon sur le message jusqu'à ce qu'un humain décide. */
class InboxDraftService
{
    private const ACTION = 'reply';

    public function __construct(
        private AgentCatalog $catalog,
        private InboxConversationBuilder $conversations,
        private InboxBatchReplyGenerator $batchGenerator,
        private InboxReplyGenerator $singleGenerator,
        private InboxSyncService $sync,
    ) {}

    /**
     * Toutes les réponses d'un compte en un seul prompt quand il a un persona.
     *
     * @param  Collection<int, InboxItem>  $items
     * @return array<int, string> Texte par identifiant de message.
     */
    public function batch(SocialAccount $account, Collection $items, ?bool $usePersona = null): array
    {
        return $this->canBatch($account, $usePersona)
            ? $this->draft($account, $items)['replies']
            : $items->mapWithKeys(fn (InboxItem $item) => [$item->id => $this->one($item, $account, $usePersona)])->filter()->all();
    }

    /** Une demande explicite doit aboutir : si le lot écarte le message, on le rédige seul. */
    public function suggest(InboxItem $item, SocialAccount $account, ?bool $usePersona = null): ?string
    {
        return $this->batch($account, collect([$item]), $usePersona)[$item->id] ?? $this->one($item, $account, $usePersona);
    }

    /** @return array{created: int, tokens: int} */
    public function prepare(SocialAccount $account, int $limit): array
    {
        $state = $this->catalog->forAccount($account)->get(self::ACTION);
        $room = min($limit, config('agent.batch_limit'), $state['left'] ?? 0);
        if (! ($state['enabled'] ?? false) || $room < 1) {
            return ['created' => 0, 'tokens' => 0];
        }

        $this->refresh($account);

        $now = now();
        $items = InboxItem::where('social_account_id', $account->id)
            ->whereBetween('posted_at', [CommentFreshness::cutoff($now), $now])
            ->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('reply_scheduled_at')
            ->whereNull('reply_draft')->whereNull('reply_content')->orderByDesc('posted_at')->limit($room)->get();
        if ($items->isEmpty()) {
            return ['created' => 0, 'tokens' => 0];
        }

        $result = $this->draft($account, $items);

        foreach ($items->whereIn('id', array_keys($result['replies'])) as $item) {
            $this->log($account, self::ACTION, ['target_uri' => (string) $item->post_url, 'target_author' => $item->author_username, 'target_text' => $result['replies'][$item->id]]);
        }
        $this->log($account, 'reply.prepare', ['metadata' => ['conversations' => $items->count(), 'created' => count($result['replies']), 'tokens' => $result['tokens']]]);

        return ['created' => count($result['replies']), 'tokens' => $result['tokens']];
    }

    /** @return array{replies: array<int, string>, tokens: int} */
    private function draft(SocialAccount $account, Collection $items): array
    {
        $conversations = $this->conversations->build($account, $items);
        if ($conversations === []) {
            return ['replies' => [], 'tokens' => 0];
        }

        $result = $this->batchGenerator->generate($account, $conversations);
        $replies = [];
        foreach ($conversations as $i => $conversation) {
            if ($text = $result['replies'][$i + 1] ?? null) {
                $this->keep($conversation['inbox_item_id'], $text);
                $replies[$conversation['inbox_item_id']] = $text;
            }
        }

        return ['replies' => $replies, 'tokens' => $result['tokens']];
    }

    private function one(InboxItem $item, SocialAccount $account, ?bool $usePersona): ?string
    {
        $text = $this->singleGenerator->generate($item, $account, $usePersona);
        if ($text) {
            $this->keep($item->id, $text);
        }

        return $text;
    }

    private function keep(int $itemId, string $text): void
    {
        InboxItem::whereKey($itemId)->whereNull('replied_at')->update(['reply_draft' => $text]);
    }

    private function canBatch(SocialAccount $account, ?bool $usePersona): bool
    {
        return ($usePersona ?? (bool) Setting::get('inbox_use_persona', true))
            && $account->persona?->is_active && filled($account->persona->system_prompt);
    }

    /** Une lecture ratée n'empêche pas de traiter ce qui est déjà dans la messagerie. */
    private function refresh(SocialAccount $account): void
    {
        try {
            $this->sync->syncAccount($account);
        } catch (\Throwable $e) {
            Log::warning('Inbox : synchro avant préparation échouée', ['account_id' => $account->id, 'error' => $e->getMessage()]);
        }
    }

    private function log(SocialAccount $account, string $action, array $fields): void
    {
        BotActionLog::create($fields + [
            'social_account_id' => $account->id, 'action_type' => $this->catalog->logType($action), 'source' => AgentCatalog::SOURCE,
            'target_uri' => '', 'success' => true, 'performed_at' => now(),
        ]);
    }
}
