<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Agent\CommunityManagerWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Fil « Pour toi » de Threads (https://www.threads.com/), lu par Companion sur le NAS. Les réponses aux commentaires
 * reçus restent sur l'API (messagerie). Voir docs/flux-engagement-scripte.md.
 */
class ThreadsFeedEngageCommand extends FeedEngageCommand
{
    protected $signature = 'threads:feed-engage {account? : Compte Threads avec session Companion} {--all : Tous les comptes dont l’engagement est allumé}
        {--commit : Aimer et commenter pour de vrai (interrupteur du compte requis)} {--json : Sortie JSON}';

    protected $description = 'Lit le fil « Pour toi » de Threads et décide sans modèle de langue des likes et commentaires spintax';

    protected function network(): string
    {
        return 'Threads';
    }

    protected function accounts(): Builder
    {
        return SocialAccount::with('platform')->whereNotNull('browser_profile')->whereHas('platform', fn ($q) => $q->where('slug', 'threads'));
    }

    protected function unavailable(SocialAccount $account): ?string
    {
        return blank($account->browser_username) || $account->browser_session_status !== 'connected'
            ? 'Session Companion Threads sur le NAS non connectée.' : null;
    }

    protected function readTask(SocialAccount $account, array $settings): array
    {
        // « Pour toi », pas /following/ : Hermès garde le fil des abonnements tant que Xavier n'a pas tranché.
        return ['threads.agent.read', ['url' => 'https://www.threads.com/', 'action' => 'feed.read', 'feed' => 'foryou',
            'username' => $account->browser_username, 'limit' => $settings['read_limit'], 'screens' => 12,
            'activity_window' => app(CommunityManagerWindow::class)->specification()]];
    }

    protected function remember(SocialAccount $account, array $payload, array $result): string
    {
        $snapshot = $this->snapshotId();
        Cache::put('threads-snapshot:'.$snapshot, ['account_id' => $account->id, 'read_action' => 'feed.read', 'result' => $result], now()->addHour());

        return $snapshot;
    }
}
