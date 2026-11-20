<?php

namespace App\Console\Commands;

use App\Models\BrowserTask;
use App\Models\MetaEngagement;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Agent\MetaBrowserAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Les suivis manuels ne sont jamais candidats. Les deux preuves sont des tâches de lecture distinctes. */
class InstagramUnfollowDue extends Command
{
    protected $signature = 'instagram:unfollow-due {--account=} {--commit}';

    protected $description = 'Vérifier les retours et désabonner les suivis RS-Max arrivés à échéance (aperçu par défaut)';

    public function handle(MetaBrowserAgent $agent, AgentCatalog $catalog): int
    {
        if (! app(CommunityManagerWindow::class)->isOpen()) {
            return self::SUCCESS;
        }
        $accounts = SocialAccount::with('platform')->whereHas('platform', fn ($q) => $q->where('slug', 'instagram'))
            ->where('driver', 'browser')->where('browser_session_status', 'connected')
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($accounts as $account) {
            if (! ($catalog->forAccount($account)->get('unfollow')['enabled'] ?? false)
                || BrowserTask::where('profile', $account->browser_profile)->whereIn('status', ['pending', 'running'])->exists()) {
                continue;
            }
            $contacts = SocialContact::where('social_account_id', $account->id)->where('metadata->follow_source', 'agent')
                ->whereNull('unfollowed_at')->where('followed_at', '<=', now()->subDays($account->agent_strategy['unfollow_after_days'] ?? 7))
                ->orderBy('follow_back_checked_at')->limit(5)->get();
            foreach ($contacts as $c) {
                if (MetaEngagement::where('social_account_id', $account->id)->where('action', 'unfollow')->where('target_key', hash('sha256', 'profile:'.$c->handle))->exists()
                    || $c->follow_back === 'yes') {
                    continue;
                }
                $this->line($account->id.' · @'.$c->handle.' · vérification du retour');
                if (! $this->option('commit')) {
                    continue;
                }
                try {
                    $eligible = true;
                    try {
                        $agent->assertUnfollow($account, $c->handle);
                    } catch (\Throwable) {
                        $eligible = false;
                    }
                    if (! $eligible) {
                        if (! $c->follow_back_checked_at || $c->follow_back_checked_at->lte(now()->subMinutes(15))) {
                            $agent->read($account, ['action' => 'followback.read', 'handle' => $c->handle]);
                        }
                    } else {
                        $read = BrowserTask::where('social_account_id', $account->id)->where('profile', $account->browser_profile)
                            ->where('kind', 'instagram.agent.read')->where('payload->action', 'profile.read')->where('payload->handle', $c->handle)
                            ->where('status', 'done')->where('finished_at', '>', now()->subMinutes(10))->latest('id')->first();
                        if ($read) {
                            $agent->submit($account, $read, ['action' => 'unfollow', 'dry_run' => false, 'request_key' => (string) Str::uuid()]);
                        } else {
                            $agent->read($account, ['action' => 'profile.read', 'handle' => $c->handle]);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn($account->id.' · '.$e->getMessage());
                }
                break; // un seul nouvel ordre par profil et par passage
            }
        }

        return self::SUCCESS;
    }
}
