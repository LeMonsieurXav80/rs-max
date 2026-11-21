<?php

namespace App\Console\Commands;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\InstagramCommentLikes;
use App\Services\Agent\MetaBrowserAgent;
use App\Services\Browser\MetaSessionService;
use Illuminate\Console\Command;

class InstagramLikeComments extends Command
{
    protected $signature = 'instagram:like-comments {--account=} {--commit}';

    protected $description = 'Lire les hashtags choisis puis aimer leurs commentaires sur le VPS (aperçu par défaut)';

    public function handle(InstagramCommentLikes $likes): int
    {
        $accounts = SocialAccount::with('platform')->whereHas('platform', fn ($q) => $q->where('slug', 'instagram'))
            ->where('driver', 'browser')->whereIn('browser_session_status', ['connected', 'pending'])
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get()
            ->sortBy(fn ($a) => BrowserTask::where('social_account_id', $a->id)->where('payload->origin', InstagramCommentLikes::ORIGIN)->max('id') ?? 0);
        foreach ($accounts as $account) {
            try {
                if ($account->browser_session_status === 'pending') {
                    if (! ($account->agent_strategy['instagram_comment_likes_enabled'] ?? false)
                        || ! app(\App\Services\Agent\CommunityManagerWindow::class)->isOpen()
                        || app(MetaBrowserAgent::class)->platformPauseUntil($account)
                        || BrowserTask::where('profile', $account->browser_profile)->whereIn('status', ['pending', 'running'])->exists()
                        || BrowserTask::where('social_account_id', $account->id)->where('kind', 'instagram.whoami')
                            ->where('status', 'failed')->where('finished_at', '>', now()->subMinutes(15))->exists()
                        || ! $this->option('commit')) {
                        $this->line(json_encode(['account_id' => $account->id, 'status' => 'session_pending']));

                        continue;
                    }
                    app(MetaSessionService::class)->check($account);
                    $this->line(json_encode(['account_id' => $account->id, 'status' => $account->browser_session_status]));

                    continue;
                }
                $this->line(json_encode(['account_id' => $account->id] + $likes->tick($account, (bool) $this->option('commit')), JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->warn($account->id.' · '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
