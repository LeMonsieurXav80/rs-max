<?php

namespace App\Services\Agent;

use App\Models\AgentAccountAction;
use App\Models\BotActionLog;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

/** Ce que l'agent peut faire sur chaque compte : le catalogue, plus les réglages du compte. */
class AgentCatalog
{
    public const SOURCE = 'agent';

    public function platforms(): array
    {
        return config('agent.platforms');
    }

    public function logType(string $action): string
    {
        return 'agent.'.$action;
    }

    /** @return array<string, array<string, mixed>>|null */
    public function action(SocialAccount $account, string $action): ?array
    {
        return config("agent.platforms.{$account->platform->slug}.actions")[$action] ?? null;
    }

    public function usedToday(SocialAccount $account, string $action): int
    {
        return BotActionLog::where('social_account_id', $account->id)
            ->where('action_type', $this->logType($action))
            ->where('success', true)
            ->where('created_at', '>=', today())
            ->count();
    }

    /** @return Collection<string, array<string, mixed>> */
    public function forAccount(SocialAccount $account): Collection
    {
        $saved = $account->agentActions()->get()->keyBy('action');
        $actions = config("agent.platforms.{$account->platform->slug}.actions", []);

        return collect($actions)->map(function (array $action, string $key) use ($account, $saved) {
            $setting = $saved->get($key);
            $limit = $setting?->daily_limit ?? ($action['limit'] ?? 0);
            $used = $action['status'] === 'ready' ? $this->usedToday($account, $key) : 0;

            return $action + ['enabled' => (bool) $setting?->enabled, 'daily_limit' => $limit, 'used' => $used, 'left' => max(0, $limit - $used)];
        });
    }

    public function configure(SocialAccount $account, string $action, bool $enabled, int $dailyLimit): AgentAccountAction
    {
        return $account->agentActions()->updateOrCreate(['action' => $action], ['enabled' => $enabled, 'daily_limit' => $dailyLimit]);
    }
}
