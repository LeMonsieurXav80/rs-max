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
 * Hermès) ; simple merci, ou commentaire écarté par Hermès (« not_relevant » : compliments sur le physique…) ⇒ like,
 * jamais de réponse (décision de Xavier, 16/11/2026). Mêmes chemins qu'Hermès (`submit`, `LikeInboxItem`) : cadence,
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
        foreach ($this->accounts() as $account) {
            $items = InboxItem::where('social_account_id', $account->id)->where('type', '!=', 'dm')
                ->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('reply_scheduled_at')
                ->where('reply_attempts', 0)
                // Un report pour indécision d'Hermès (« agent_no_decision », « retry_later ») ne concerne pas une règle
                // sans modèle : un emoji seul n'appelle aucune décision. Les autres reports restent respectés.
                ->where(fn ($q) => $q->whereNull('agent_retry_at')->orWhere('agent_retry_at', '<=', now())
                    ->orWhereIn('agent_review_reason', [InboxAgentReview::NO_DECISION, 'retry_later']))
                ->orderBy('posted_at')->limit(200)->get()
                ->filter(fn (InboxItem $i) => CommentFreshness::allows($i->posted_at) && ! $policy->isOwn($i, $account));
            $done = 0;
            $discarded = InboxItem::where('social_account_id', $account->id)->where('type', '!=', 'dm')
                ->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('liked_at')->where('reply_attempts', 0)
                ->where('agent_review_reason', 'not_relevant')->orderBy('posted_at')->limit((int) $this->option('limit'))->get()
                ->filter(fn (InboxItem $i) => CommentFreshness::allows($i->posted_at) && ! $policy->isOwn($i, $account));
            foreach ($this->canLike($account) ? $discarded : [] as $item) {
                $this->like($item, 'écarté par Hermès', $commit, $review);
            }
            foreach ($items as $item) {
                if ($done >= (int) $this->option('limit')) {
                    break;
                }
                if ($policy->isThanks($item->content)) {
                    $this->canLike($account) ? $this->like($item, 'merci', $commit, $review) : $this->skip($item, $commit, $review);
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

    /** Comptes traités : TikTok sur téléphone. */
    protected function accounts(): iterable
    {
        return SocialAccount::with('platform')->where('driver', 'browser')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'tiktok'))->get()->filter->usesPhone();
    }

    protected function canLike(SocialAccount $account): bool
    {
        return true;
    }

    /** Simple merci sans like possible : jamais de réponse, retiré de la file d'Hermès (aucun jeton). */
    private function skip(InboxItem $item, bool $commit, InboxAgentReview $review): void
    {
        $this->line("#{$item->id} merci, @{$item->author_username} : sans réponse".($commit ? ', retiré de la file' : ''));
        if ($commit) {
            $review->defer($item, 'not_relevant');
        }
    }

    /** Like, une seule fois : `not_relevant_liked` empêche le passage suivant de le redemander. */
    private function like(InboxItem $item, string $why, bool $commit, InboxAgentReview $review): void
    {
        $this->line("#{$item->id} {$why}, @{$item->author_username} : like".($commit ? ' programmé' : ''));
        if (! $commit) {
            return;
        }
        LikeInboxItem::dispatch($item->id);
        $review->defer($item, 'not_relevant');
        $item->update(['agent_review_reason' => 'not_relevant_liked']);
    }
}
