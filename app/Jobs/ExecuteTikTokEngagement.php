<?php

namespace App\Jobs;

use App\Models\BotActionLog;
use App\Models\TikTokEngagement;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use App\Services\Browser\BrowserUncertainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Un geste réservé une seule fois, puis une preuve après rechargement. */
class ExecuteTikTokEngagement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $engagementId) {}

    public function handle(BrowserTaskQueue $browser, AgentCatalog $catalog): void
    {
        Cache::lock('tiktok-engagement:'.$this->engagementId, 310)->block(2, function () use ($browser, $catalog) {
            $row = TikTokEngagement::with('socialAccount.persona', 'socialAccount.platform')->find($this->engagementId);
            if (! $row || $row->status !== 'queued') {
                return;
            }
            $account = $row->socialAccount;
            // Le verrou de compte sérialise aussi le décompte du quota.
            Cache::lock('tiktok-engagement-account:'.$account->id, 310)->block(3, function () use ($row, $account, $browser, $catalog) {
                $state = $catalog->forAccount($account)->get($row->action);
                if (! $state || ! $state['enabled'] || $state['left'] < 1 || ! $account->usesBrowser()
                    || $account->browser_session_status !== 'connected' || $account->browser_username !== $row->payload['username']
                    || (isset($row->payload['activity_window']) && ! app(CommunityManagerWindow::class)->isOpen($row->payload['activity_window']))
                    || ($row->persona_hash && (! $account->persona?->is_active || $row->persona_hash !== TikTokEngagement::personaHash($account)))) {
                    $row->update(['status' => 'failed', 'error' => 'Compte, quota ou persona modifié : aucun geste.']);

                    return;
                }
                if (! TikTokEngagement::whereKey($row->id)->where('status', 'queued')->update(['status' => 'running'])) {
                    return;
                }
                try {
                    $result = $browser->run($account, 'tiktok.'.$row->action, $row->payload, 270);
                    $confirmed = ($result['confirmed'] ?? false) === true;
                    $row->update(['status' => $confirmed ? 'confirmed' : 'uncertain', 'result' => $result,
                        'error' => $confirmed ? null : 'Absence de preuve après rechargement. Vérifier TikTok, sans relancer.']);
                } catch (BrowserUncertainException $e) {
                    $row->update(['status' => 'uncertain', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                } catch (BrowserException $e) {
                    $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                }
                BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $catalog->logType($row->action),
                    'source' => AgentCatalog::SOURCE, 'target_uri' => $row->payload['url'], 'success' => $row->status === 'confirmed',
                    'error' => $row->error, 'metadata' => ['engagement_id' => $row->id], 'performed_at' => now()]);
            });
        });
    }

    public function failed(?\Throwable $e): void
    {
        TikTokEngagement::whereKey($this->engagementId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'uncertain', 'error' => 'Exécution interrompue : vérifier TikTok avant toute autre action.']);
    }
}
