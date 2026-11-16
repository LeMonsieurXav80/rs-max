<?php

namespace App\Console\Commands;

use App\Jobs\LikeInboxItem;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Agent\CommentFreshness;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Console\Command;

/**
 * Réponses TikTok en échec (comptes sur téléphone) rapprochées de TikTok Studio, sans modèle et sans geste.
 * Une réponse incertaine n'est jamais renvoyée : sans ce rapprochement, l'échec reste à vie dans /inbox, qu'elle
 * soit publiée, retenue par TikTok ou jamais partie. Studio tranche : « Avec réponse » ⇒ répondu ; « Pas de
 * réponse » ⇒ de nouveau à traiter s'il est encore récent ET qu'aucune tentative n'a pu partir (sinon la réponse
 * a pu être retenue par TikTok, code 2 : jamais de renvoi) ; introuvable ou trop ancien ⇒ classé.
 */
class TikTokReconcileFailedCommand extends Command
{
    protected $signature = 'tiktok:reconcile-failed {--commit : Appliquer le résultat}';

    protected $description = 'Rapproche les réponses TikTok en échec des listes de TikTok Studio (téléphone), sans modèle';

    public function handle(BrowserTaskQueue $queue): int
    {
        $accounts = SocialAccount::with('platform')->where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'tiktok'))->get()->filter->usesPhone();
        foreach ($accounts as $account) {
            $items = InboxItem::where('social_account_id', $account->id)->where('status', 'reply_failed')->whereNull('replied_at')->get();
            if ($items->isEmpty()) {
                continue;
            }
            try {
                $result = $queue->run($account, 'tiktok.inbox.reconcile', ['username' => $account->browser_username,
                    'items' => $items->map(fn ($i) => ['id' => $i->id, 'author' => $i->author_username, 'content' => (string) $i->content,
                        'posted_at' => $i->posted_at?->toIso8601String()])->values()->all()], 900);
            } catch (BrowserException $e) {
                $this->error("{$account->name} : {$e->getMessage()}");

                continue;
            }
            $counts = [];
            foreach ($items as $item) {
                $status = $result['statuses'][(string) $item->id] ?? 'absent';
                $action = match (true) {
                    $status === 'answered' => 'répondu',
                    $status === 'pending' && CommentFreshness::allows($item->posted_at) && ! $this->mayHaveSent($item) => 'à traiter',
                    in_array($status, ['pending', 'absent'], true) => 'classé',
                    default => 'inchangé',
                };
                $counts[$action] = ($counts[$action] ?? 0) + 1;
                $this->line("#{$item->id} @{$item->author_username} : {$status} ⇒ {$action}");
                if (! $this->option('commit')) {
                    continue;
                }
                match ($action) {
                    'répondu' => $item->update(['status' => 'replied', 'replied_at' => $item->updated_at, 'reply_scheduled_at' => null]),
                    'à traiter' => $item->update(['status' => 'read', 'reply_content' => null, 'reply_scheduled_at' => null,
                        'reply_attempts' => 0, 'agent_review_reason' => null, 'agent_retry_at' => null]),
                    'classé' => $item->update(['status' => 'ignored', 'agent_review_reason' => match ($status) {
                        'absent' => 'reconciled_absent', default => $this->mayHaveSent($item) ? 'reconciled_withheld' : 'reconciled_expired'
                    }]),
                    default => null,
                };
                if ($action === 'répondu' && ! $item->liked_at) {
                    LikeInboxItem::dispatch($item->id);
                }
            }
            $this->info($account->name.' : '.collect($counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ')
                ." (Studio : {$result['pending']} sans réponse, {$result['answered']} avec réponse)");
        }

        return self::SUCCESS;
    }

    /** Une tentative a-t-elle pu publier (envoi cliqué, résultat incertain ou confirmé) ? Alors jamais de renvoi. */
    private function mayHaveSent(InboxItem $item): bool
    {
        return BrowserTask::where('social_account_id', $item->social_account_id)->where('kind', 'tiktok.reply')
            ->where(fn ($q) => $q->where('payload->inbox_item_id', $item->id)
                ->orWhere(fn ($q) => $q->where('payload->target->author', $item->author_username)->where('payload->target->content', $item->content)))
            ->get()->contains(fn ($t) => in_array($t->status, [BrowserTask::DONE, BrowserTask::UNCERTAIN, BrowserTask::RUNNING], true)
                || ($t->result['send_clicked'] ?? false) === true);
    }
}
