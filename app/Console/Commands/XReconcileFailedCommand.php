<?php

namespace App\Console\Commands;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Inbox\XReplyReconciler;
use Illuminate\Console\Command;

/**
 * Réponses X en échec (incertaines) rapprochées par une lecture du fil de la mention, sans modèle et sans geste.
 * Une réponse incertaine n'est jamais renvoyée : retrouvée ⇒ répondu ; introuvable ⇒ rien n'est conclu (X ne montre
 * pas toutes les réponses), sauf au-delà de 7 jours où l'échec est classé pour ne plus encombrer /inbox.
 */
class XReconcileFailedCommand extends Command
{
    protected $signature = 'x:reconcile-failed {--commit : Appliquer le résultat} {--limit=10 : Mentions lues par compte}';

    protected $description = 'Rapproche les réponses X en échec en relisant le fil de chaque mention, sans modèle';

    public function handle(BrowserTaskQueue $queue, XReplyReconciler $reconciler): int
    {
        $commit = (bool) $this->option('commit');
        $accounts = SocialAccount::with('platform')->where('driver', 'browser')->whereHas('platform', fn ($q) => $q->where('slug', 'twitter'))->get();
        foreach ($accounts as $account) {
            $items = InboxItem::where('social_account_id', $account->id)->where('status', 'reply_failed')->whereNull('replied_at')
                ->whereNotNull('reply_content')->orderByDesc('posted_at')->limit((int) $this->option('limit'))->get();
            $counts = [];
            foreach ($items as $item) {
                try {
                    // Permalien avec l'auteur : Companion compare l'auteur de l'URL à celui de la page (`/i/web/` est refusé).
                    $read = $queue->run($account, 'x.agent.read', ['url' => 'https://x.com/'.ltrim((string) $item->author_username, '@').'/status/'.$item->external_id,
                        'action' => 'thread.read', 'username' => $account->browser_username, 'target_id' => (string) $item->external_id], 110);
                    $result = $reconciler->reconcileFromThread($item, $read, ! $commit);
                } catch (BrowserException $e) {
                    $result = ['found' => false, 'reason' => $e->getMessage()];
                } catch (\Illuminate\Database\QueryException $e) {
                    // Verrou tenu ailleurs (vu le 16/11/2026) : ce commentaire attendra le prochain passage.
                    $this->warn("#{$item->id} : base occupée, repris au prochain passage.");

                    continue;
                }
                $old = $item->posted_at?->lt(now()->subDays(7)) ?? true;
                $action = $result['found'] ? 'répondu' : ($old ? 'classé' : 'inchangé');
                $counts[$action] = ($counts[$action] ?? 0) + 1;
                $this->line("#{$item->id} @{$item->author_username} : {$action}".($result['found'] ? '' : ' — '.($result['reason'] ?? '')));
                if ($commit && $action === 'classé') {
                    $item->update(['status' => 'ignored', 'agent_review_reason' => 'reconciled_unproven']);
                }
            }
            if ($counts) {
                $this->info($account->name.' : '.collect($counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
