<?php

namespace App\Services\Agent;

use App\Models\SocialAccount;
use App\Services\Inbox\ReplyLanguage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountStrategy
{
    public const LISTS = ['hashtags' => 'Hashtags à rechercher', 'target_accounts' => 'Comptes à suivre / explorer', 'search_queries' => 'Recherches spécifiques', 'exclusions' => 'Mots et comptes à exclure'];

    /** @return list<string> Requêtes X issues des réglages du compte. */
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

        $excluded = [];
        foreach ($strategy['exclusions'] ?? [] as $value) {
            if (is_string($value) && preg_match('/^@([a-z0-9_]{1,15})$/iD', trim($value), $match)) {
                $excluded[strtolower($match[1])] = true;
            }
        }
        foreach ($strategy['target_accounts'] ?? [] as $value) {
            if (is_string($value) && preg_match('~^(?:https://(?:www\.)?(?:x|twitter)\.com/)?@?([a-z0-9_]{1,15})/?$~iD', trim($value), $match)) {
                $handle = strtolower($match[1]);
                if (! isset($excluded[$handle])) {
                    $queries[] = 'from:'.$handle;
                }
            }
        }

        $distinct = [];
        foreach ($queries as $query) {
            $distinct[mb_convert_case($query, MB_CASE_FOLD)] ??= $query;
        }

        return array_values($distinct);
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
