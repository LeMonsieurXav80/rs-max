<?php

namespace App\Services\Agent;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Models\SocialContact;
use App\Models\XEngagement;
use App\Services\Inbox\ReplyLanguage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountStrategy
{
    public const LISTS = ['hashtags' => 'Hashtags à rechercher', 'target_accounts' => 'Comptes à suivre / explorer', 'search_queries' => 'Recherches spécifiques', 'exclusions' => 'Mots et comptes à exclure'];

    public const X_SEARCH_QUERY_LIMIT = 263;

    public function xSearchLanguage(SocialAccount $account): ?string
    {
        $languages = array_values($account->languages ?? []);

        return count($languages) === 1 && is_string($languages[0]) && isset(ReplyLanguage::LABELS[$languages[0]])
            ? $languages[0] : null;
    }

    /** @return list<string> Requêtes X prêtes à exécuter, dans l'ordre de la stratégie. */
    public function xDiscoveryQueries(SocialAccount $account): array
    {
        $strategy = $account->agent_strategy ?? [];
        $queries = [];
        foreach ($strategy['search_queries'] ?? [] as $value) {
            $query = is_string($value) ? trim($value) : '';
            if ($query !== '' && mb_strlen($query) <= 255) {
                $queries[] = $query;
            }
        }
        foreach ($strategy['hashtags'] ?? [] as $value) {
            $tag = is_string($value) ? ltrim(trim($value), '#') : '';
            if (preg_match('/^[\p{L}\p{N}_]{1,254}$/uD', $tag) && ! preg_match('/^\p{Nd}+$/uD', $tag)) {
                $queries[] = '#'.$tag;
            }
        }

        foreach ($this->xTargetHandles($account) as $handle) {
            $queries[] = 'from:'.$handle;
        }

        $language = $this->xSearchLanguage($account);
        $distinct = [];
        foreach ($queries as $query) {
            if ($language !== null && ! preg_match('/(*UCP)(?:^|[\s(])lang:/iu', $query)) {
                $query .= ' lang:'.$language;
            }
            $distinct[mb_convert_case($query, MB_CASE_FOLD)] ??= $query;
        }

        return array_values($distinct);
    }

    /** @return array{handle:string,source:string}|null */
    public function xFollowCandidate(SocialAccount $account): ?array
    {
        $candidates = array_fill_keys($this->xTargetHandles($account), 'configured');
        $conversations = XEngagement::where('social_account_id', $account->id)
            ->where('action', 'reply')->where('status', 'confirmed')->where('requested_at', '>=', now()->subDays(7))
            ->latest('requested_at')->limit(30)->get(['payload', 'result']);
        foreach ($conversations as $conversation) {
            if (($conversation->result['unchanged'] ?? false) === false && ($handle = $conversation->payload['target']['author'] ?? null)) {
                $candidates[strtolower($handle)] ??= 'conversation';
            }
        }
        $discovered = SocialContact::where('social_account_id', $account->id)->where('source', 'search.read')
            ->where('last_read_at', '>=', now()->subDays(7))->latest('last_read_at')->limit(30)->pluck('handle');
        foreach ($discovered as $handle) {
            $candidates[$handle] ??= 'search';
        }

        $candidates = array_diff_key($candidates, $this->xExcludedHandles($account), [strtolower((string) $account->browser_username) => true]);
        $candidates = array_filter($candidates, fn ($handle) => preg_match('/^[a-z0-9_]{1,15}$/D', (string) $handle), ARRAY_FILTER_USE_KEY);
        if ($candidates === []) {
            return null;
        }

        $handles = array_map('strval', array_keys($candidates));
        $keys = array_combine(array_map(fn ($handle) => hash('sha256', $handle), $handles), $handles);
        $reserved = XEngagement::where('social_account_id', $account->id)->where('action', 'follow')->where('status', '!=', 'prepared')
            ->whereIn('target_key', array_keys($keys))->pluck('target_key')->map(fn ($key) => $keys[$key])->all();
        $following = SocialContact::where('social_account_id', $account->id)->whereIn('handle', $handles)
            ->whereNotNull('followed_at')->whereNull('unfollowed_at')->pluck('handle')->all();
        $checked = BrowserTask::where('social_account_id', $account->id)->where('kind', 'x.agent.read')
            ->where('payload->action', 'profile.read')->whereIn('payload->handle', $handles)
            ->where('created_at', '>=', now()->subHour())->get(['payload'])->map(fn (BrowserTask $task) => $task->payload['handle'])->all();
        $eligible = array_diff_key($candidates, array_fill_keys([...$reserved, ...$following, ...$checked], true));
        $handle = array_key_first($eligible);

        return $handle === null ? null : ['handle' => (string) $handle, 'source' => $eligible[$handle]];
    }

    private function xExcludedHandles(SocialAccount $account): array
    {
        $excluded = [];
        foreach ($account->agent_strategy['exclusions'] ?? [] as $value) {
            if (is_string($value) && preg_match('/^@([a-z0-9_]{1,15})$/iD', trim($value), $match)) {
                $excluded[strtolower($match[1])] = true;
            }
        }

        return $excluded;
    }

    /** @return list<string> */
    private function xTargetHandles(SocialAccount $account): array
    {
        $handles = [];
        $excluded = $this->xExcludedHandles($account);
        foreach ($account->agent_strategy['target_accounts'] ?? [] as $value) {
            if (is_string($value) && preg_match('~^(?:https://(?:www\.)?(?:x|twitter)\.com/)?@?([a-z0-9_]{1,15})/?$~iD', trim($value), $match)) {
                $handle = strtolower($match[1]);
                if (! isset($excluded[$handle])) {
                    $handles[] = $handle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    public function validate(Request $request): array
    {
        // Les formulaires emploient une ligne par entrée ; l’API accepte des tableaux.
        $strategy = $request->input('agent_strategy', []);
        if (is_array($strategy)) {
            if (array_key_exists('editorial_topics', $strategy) && $strategy['editorial_topics'] === null) {
                $strategy['editorial_topics'] = [];
            }

            foreach (array_keys(self::LISTS) as $key) {
                if (array_key_exists($key, $strategy) && ($strategy[$key] === null || is_string($strategy[$key]))) {
                    $strategy[$key] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $strategy[$key]))));
                }
            }
            $request->merge(['agent_strategy' => $strategy]);
        }
        foreach (['max_hourly', 'max_daily'] as $field) {
            if ($request->exists('agent_adaptation.'.$field) && $request->input('agent_adaptation.'.$field) === null) {
                $adaptation = $request->input('agent_adaptation', []);
                unset($adaptation[$field]);
                $request->merge(['agent_adaptation' => $adaptation]);
            }
        }
        $rules = ['agent_hourly_limit' => 'sometimes|nullable|integer|min:0|max:1000',
            'agent_daily_limit' => 'sometimes|nullable|integer|min:0|max:100000',
            'agent_adaptation' => 'sometimes|array:enabled,max_hourly,max_daily',
            'agent_adaptation.enabled' => 'sometimes|boolean',
            'agent_adaptation.max_hourly' => 'sometimes|integer|min:1|max:1000',
            'agent_adaptation.max_daily' => 'sometimes|integer|min:2|max:100000',
            'agent_min_interval_seconds' => 'sometimes|integer|min:0|max:86400',
            'agent_strategy' => 'sometimes|array:audience,objective,hashtags,target_accounts,search_queries,exclusions,unfollow_after_days,editorial_topics,reply_language',
            'agent_strategy.reply_language' => ['sometimes', 'nullable', Rule::in(array_keys(ReplyLanguage::LABELS))],
            'agent_strategy.editorial_topics' => 'sometimes|array',
            'agent_strategy.editorial_topics.*' => ['string', 'distinct', Rule::in(array_keys(config('editorial_calendar.topics')))],
            'agent_strategy.unfollow_after_days' => 'sometimes|integer|min:1|max:365',
            'agent_strategy.audience' => 'sometimes|nullable|string|max:4000', 'agent_strategy.objective' => 'sometimes|nullable|string|max:2000'];
        foreach (array_keys(self::LISTS) as $key) {
            $rules['agent_strategy.'.$key] = 'sometimes|array|max:100';
            $rules['agent_strategy.'.$key.'.*'] = 'string|max:255|distinct';
        }

        return $request->validate($rules);
    }

    public function save(Request $request, SocialAccount $account): void
    {
        $data = $this->validate($request);
        $token = $request->user()?->currentAccessToken();
        $scoped = $token instanceof \Laravel\Sanctum\PersonalAccessToken && in_array('community-manager', (array) $token->abilities, true);
        if ($scoped && (array_key_exists('agent_adaptation', $data) || (($account->agent_adaptation['enabled'] ?? false)
            && array_intersect(array_keys($data), ['agent_hourly_limit', 'agent_daily_limit', 'agent_min_interval_seconds'])))) {
            abort(403, 'La politique adaptative se règle avec un accès gestionnaire complet.');
        }
        $adaptive = app(AdaptiveAccountLimits::class);
        DB::transaction(function () use ($data, $account, $adaptive, $scoped) {
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (isset($data['agent_strategy'])) {
                $data['agent_strategy'] = array_replace($account->agent_strategy ?? [], $data['agent_strategy']);
            }
            if ($scoped && (($account->agent_adaptation['enabled'] ?? false) && array_intersect(array_keys($data), ['agent_hourly_limit', 'agent_daily_limit', 'agent_min_interval_seconds']))) {
                abort(403, 'La politique adaptative se règle avec un accès gestionnaire complet.');
            }
            $old = $adaptive->values($account);
            $oldPolicy = $account->agent_adaptation;
            if (isset($data['agent_adaptation'])) {
                $data['agent_adaptation'] = array_replace($oldPolicy ?? [], $data['agent_adaptation']);
            }
            $account->fill($data);
            $policy = $account->agent_adaptation ?? [];
            if ($policy['enabled'] ?? false) {
                if (empty($policy['max_hourly']) || empty($policy['max_daily']) || ! $account->agent_hourly_limit || ! $account->agent_daily_limit) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['agent_adaptation' => 'Indiquer une cadence actuelle positive et les deux plafonds pour activer l’adaptation.']);
                }
                $account->agent_hourly_limit = min($account->agent_hourly_limit, $policy['max_hourly']);
                $account->agent_daily_limit = min($account->agent_daily_limit, $policy['max_daily']);
                $account->agent_min_interval_seconds = $adaptive->interval($account->agent_hourly_limit);
            }
            if ($policy !== ($oldPolicy ?? []) || $old !== $adaptive->values($account)) {
                $account->agent_adaptation_state = ($policy['enabled'] ?? false) ? ['enabled_at' => now()->toIso8601String(), 'window_started_at' => now()->toIso8601String()] : null;
                $account->save();
                if ($policy['enabled'] ?? false) {
                    $adaptive->scaleActions($account);
                }
                $adaptive->record($account, $old, 'Réglage gestionnaire de la cadence et de ses plafonds.', ['previous_policy' => $oldPolicy, 'policy' => $policy]);
            } else {
                $account->save();
            }
        });
    }
}
