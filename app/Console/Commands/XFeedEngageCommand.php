<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\XEngagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Fil « Pour vous » de X. Voir docs/flux-engagement-scripte.md. */
class XFeedEngageCommand extends FeedEngageCommand
{
    protected $signature = 'x:feed-engage {account? : Compte X en mode navigateur} {--all : Tous les comptes dont l’engagement est allumé}
        {--commit : Aimer et commenter pour de vrai (interrupteur du compte requis)} {--json : Sortie JSON}';

    protected $description = 'Lit le fil « Pour vous » et décide sans modèle de langue des likes et commentaires spintax';

    protected function network(): string
    {
        return 'X';
    }

    protected function accounts(): Builder
    {
        return SocialAccount::with('platform')->where('driver', 'browser')->whereHas('platform', fn ($q) => $q->where('slug', 'twitter'));
    }

    protected function unavailable(SocialAccount $account): ?string
    {
        return $account->browser_session_status !== 'connected' ? 'Session X du navigateur non connectée.' : null;
    }

    protected function readTask(SocialAccount $account, array $settings): array
    {
        return ['x.agent.read', ['url' => 'https://x.com/home', 'action' => 'feed.read', 'username' => $account->browser_username,
            'limit' => $settings['read_limit'], 'screens' => 12, 'feed' => 'foryou', 'handle' => '', 'target_id' => null,
            'activity_window' => app(CommunityManagerWindow::class)->specification()]];
    }

    protected function remember(SocialAccount $account, array $payload, array $result): string
    {
        app(XEngagementService::class)->rememberOwnReply($account, $result);
        $snapshot = $this->snapshotId();
        Cache::put('x-agent-snapshot:'.$snapshot, ['account_id' => $account->id, 'result' => $result, 'source' => $payload,
            'read_at' => now()->toIso8601String()], now()->addHour());

        return $snapshot;
    }
}
