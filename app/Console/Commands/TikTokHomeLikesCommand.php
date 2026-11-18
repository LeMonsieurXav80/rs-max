<?php

namespace App\Console\Commands;

use App\Models\AccountActionEvent;
use App\Models\BrowserTask;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\CommunityManagerWindow;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TikTokHomeLikesCommand extends Command
{
    public const PROFILES_SETTING = 'tiktok_home_like_phone_profiles';

    private const HOME = 'https://www.tiktok.com/';

    private const SLOTS = [8, 12, 16, 18, 21];

    private const PER_SLOT = 60;

    private const LIKE_TIMEOUT = 240;

    protected $signature = 'tiktok:home-likes {--commit : Exécuter les likes du passage}';

    protected $description = 'Aime les commentaires du fil TikTok « Pour toi » depuis les téléphones attribués';

    public function handle(BrowserTaskQueue $queue, AccountActionBudget $budget, CommunityManagerWindow $window): int
    {
        $local = now('Europe/Lisbon');
        $commit = (bool) $this->option('commit');
        if (! $window->isOpen() || ($commit && ! in_array($local->hour, self::SLOTS, true))) {
            return self::SUCCESS;
        }

        $profiles = json_decode((string) Setting::get(self::PROFILES_SETTING, '{}'), true, 512, JSON_THROW_ON_ERROR);
        foreach (SocialAccount::with('platform')->whereKey(array_keys($profiles))->get() as $account) {
            $phone = $profiles[$account->id];
            if ($account->platform->slug !== 'tiktok' || ! $account->usesBrowser() || $account->usesPhone()
                || ! is_string($phone) || ! preg_match('/^android-[a-z0-9_-]{1,32}$/D', $phone)
                || blank($account->browser_username)) {
                continue;
            }

            $action = $account->agentActions()->where('action', 'comment.like')->where('enabled', true)->first();
            $used = AccountActionEvent::where('social_account_id', $account->id)
                ->where('action', 'tiktok.comment.like')->where('created_at', '>=', $budget->dayStart())->count();
            $limit = min(self::PER_SLOT, max(0, min(self::PER_SLOT * count(self::SLOTS), $action?->daily_limit ?? 0) - $used));
            if ($limit === 0) {
                continue;
            }
            $this->line("@{$account->browser_username} {$phone} Pour toi, jusqu’à {$limit} likes");
            if (! $commit) {
                continue;
            }

            $lock = Cache::lock('tiktok-home-likes:'.$account->id, 90 * 60);
            if (! $lock->get()) {
                continue;
            }
            try {
                $key = 'tiktok-home-likes:'.$account->id.':'.$local->format('Y-m-d-H');
                if (! Cache::has($key) && ! $budget->usage($account)['blocked']) {
                    Cache::put($key, true, $local->copy()->addDay());
                    $closing = $local->copy()->setTime(22, 0);
                    $end = $local->copy()->addMinutes(75)->min($closing);
                    $this->runPass($queue, $budget, $account, $phone, $window->specification(), $end, $limit);
                }
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }

    private function runPass(BrowserTaskQueue $queue, AccountActionBudget $budget, SocialAccount $account, string $phone, array $window, CarbonInterface $end, int $limit): void
    {
        $attempted = $confirmed = $emptyReads = 0;
        $visited = [];
        $payload = ['username' => $account->browser_username, 'activity_window' => $window, 'deadline' => $end->toIso8601String()];
        try {
            while ($attempted < $limit && $emptyReads < 3 && $end->isFuture()) {
                $before = count($visited);
                $feed = $queue->run($account, 'tiktok.feed.read', $payload + [
                    'url' => self::HOME, 'limit' => 8, 'resume' => $visited !== [],
                ], timeout: 100, profile: $phone);
                foreach ($feed['items'] ?? [] as $video) {
                    $url = $video['url'] ?? '';
                    if (! preg_match('~^https://www\.tiktok\.com/@([a-zA-Z0-9_.]+)/(?:video|photo)/[0-9]+$~D', $url, $parts)
                        || strcasecmp($parts[1], $account->browser_username) === 0 || ! empty($video['sponsored']) || isset($visited[$url])) {
                        continue;
                    }
                    $visited[$url] = true;
                    if (! $end->isFuture()) {
                        break 2;
                    }
                    $discussion = $queue->run($account, 'tiktok.video.comments', $payload + ['url' => $url], timeout: 100, profile: $phone);
                    if (($discussion['video']['url'] ?? null) !== $url) {
                        continue;
                    }
                    foreach ($discussion['comments'] ?? [] as $comment) {
                        if (($comment['liked'] ?? null) !== false || ! empty($comment['ambiguous'])
                            || blank($comment['author'] ?? '') || strcasecmp($comment['author'], $account->browser_username) === 0
                            || ! preg_match('/^[a-f0-9]{64}$/D', $comment['key'] ?? '')) {
                            continue;
                        }
                        $key = 'tiktok-home-like:'.hash('sha256', $url.'|'.$comment['key']);
                        if (BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', $key)->exists()) {
                            continue;
                        }
                        if ($attempted >= $limit || ! $this->withinCadence($budget, $account, $end->copy()->subSeconds(self::LIKE_TIMEOUT))) {
                            break 3;
                        }
                        $attempted++;
                        try {
                            $result = $queue->run($account, 'tiktok.comment.like', $payload + [
                                'url' => $url, 'target' => $comment, 'publication_key' => $key,
                            ], timeout: self::LIKE_TIMEOUT, profile: $phone);
                        } catch (BrowserException $error) {
                            if (! in_array($error->getMessage(), [
                                'Commentaire exact introuvable : aucun geste.',
                                'État du like du commentaire inconnu : aucun geste.',
                            ], true)) {
                                throw $error;
                            }

                            continue;
                        }
                        if (($result['confirmed'] ?? false) === true && ($result['proof'] ?? null) === 'after_reload'
                            && empty($result['already_liked'])) {
                            $confirmed++;
                        }
                    }
                }
                $emptyReads = count($visited) === $before ? $emptyReads + 1 : 0;
            }
        } catch (BrowserException $error) {
            $this->warn($error->getMessage());
        }
        $this->info("{$confirmed} likes ajoutés et confirmés sur {$attempted} demandes, {$limit} maximum.");
    }

    private function withinCadence(AccountActionBudget $budget, SocialAccount $account, CarbonInterface $end): bool
    {
        while ($end->isFuture()) {
            $usage = $budget->usage($account);
            if ($usage['blocked'] || $usage['daily']['remaining'] === 0) {
                return false;
            }
            $next = $usage['next_allowed_at'] ? Carbon::parse($usage['next_allowed_at']) : null;
            if ($next?->isFuture()) {
                if ($next->greaterThanOrEqualTo($end)) {
                    return false;
                }
                sleep(max(1, min(30, (int) ceil(now()->diffInSeconds($next)))));
            } else {
                return true;
            }
        }

        return false;
    }
}
