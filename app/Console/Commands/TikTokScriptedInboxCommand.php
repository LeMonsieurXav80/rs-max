<?php

namespace App\Console\Commands;

use App\Jobs\LikeInboxItem;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Agent\CommentFreshness;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\InboxAgentReview;
use App\Services\Inbox\InboxFollowup;
use App\Services\Inbox\InboxReplyService;
use Illuminate\Console\Command;

/**
 * Commentaires TikTok reçus qui n'appellent aucune rédaction, traités sans modèle de langue (comptes sur téléphone) :
 * emoji, autocollant ou GIF seul ⇒ réponse par un emoji souriant (`InboxFollowup::SMILES`, comme l'impose RS-Max à
 * Hermès) ; simple merci ⇒ like, jamais de réponse. Mêmes chemins qu'Hermès (`submit`, `LikeInboxItem`) : cadence,
 * plage horaire, file du téléphone et preuve après rechargement inchangées. Le reste attend Hermès.
 */
class TikTokScriptedInboxCommand extends Command
{
    protected $signature = 'tiktok:scripted-inbox {--commit : Répondre et aimer pour de vrai} {--limit=10 : Commentaires par passage}';

    protected $description = 'Répond aux emojis seuls et aime les simples mercis TikTok, sans modèle de langue';

    public function handle(InboxFollowup $policy, InboxReplyService $replies, InboxAgentReview $review): int
    {
        if (! app(CommunityManagerWindow::class)->isOpen()) {
            $this->line('Hors de la plage de journée : rien à faire.');

            return self::SUCCESS;
        }
        $commit = (bool) $this->option('commit');
        // Chemin autonome d'Hermès : plage horaire, plafond de la file du téléphone, envoi sans validation humaine.
        request()->attributes->set('community_manager', true);
        $accounts = SocialAccount::with('platform')->where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'tiktok'))->get()->filter->usesPhone();
        foreach ($accounts as $account) {
            $items = InboxItem::where('social_account_id', $account->id)->where('type', '!=', 'dm')
                ->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('reply_scheduled_at')
                ->where('reply_attempts', 0)->where(fn ($q) => $q->whereNull('agent_retry_at')->orWhere('agent_retry_at', '<=', now()))
                ->orderBy('posted_at')->limit(200)->get()
                ->filter(fn (InboxItem $i) => CommentFreshness::allows($i->posted_at) && ! $policy->isOwn($i, $account));
            $done = 0;
            foreach ($items as $item) {
                if ($done >= (int) $this->option('limit')) {
                    break;
                }
                if ($policy->isThanks($item->content)) {
                    $this->line("#{$item->id} merci de @{$item->author_username} : like".($commit ? ' programmé' : ''));
                    if ($commit && ! $item->liked_at) {
                        LikeInboxItem::dispatch($item->id);
                        $review->defer($item, 'not_relevant');
                    }
                    $done++;

                    continue;
                }
                if (! $policy->isEmojiOnly($item->content) || $policy->isNestedReply($item)) {
                    continue; // texte à rédiger ou réponse à un tiers : Hermès
                }
                $smile = InboxFollowup::SMILES[random_int(0, count(InboxFollowup::SMILES) - 1)];
                $this->line("#{$item->id} emoji de @{$item->author_username} : réponse {$smile}");
                if ($commit) {
                    $result = $replies->submit($item, $smile);
                    if (! ($result['success'] ?? false)) {
                        $this->warn('  '.($result['error'] ?? 'refus'));
                        if (str_contains((string) ($result['error'] ?? ''), 'File du téléphone pleine')) {
                            break;
                        }
                    }
                }
                $done++;
            }
        }

        return self::SUCCESS;
    }
}
