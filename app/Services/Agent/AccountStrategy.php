<?php

namespace App\Services\Agent;

use App\Models\SocialAccount;
use Illuminate\Http\Request;

class AccountStrategy
{
    public const LISTS = ['hashtags' => 'Hashtags à rechercher', 'target_accounts' => 'Comptes à suivre / explorer', 'search_queries' => 'Recherches spécifiques', 'exclusions' => 'Mots et comptes à exclure'];

    public function validate(Request $request): array
    {
        // Les formulaires emploient une ligne par entrée ; l’API accepte des tableaux.
        $strategy = $request->input('agent_strategy', []);
        if (is_array($strategy)) {
            foreach (array_keys(self::LISTS) as $key) {
                if (array_key_exists($key, $strategy) && ($strategy[$key] === null || is_string($strategy[$key]))) {
                    $strategy[$key] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $strategy[$key]))));
                }
            }
            $request->merge(['agent_strategy' => $strategy]);
        }
        $rules = ['agent_hourly_limit' => 'sometimes|nullable|integer|min:0|max:1000',
            'agent_min_interval_seconds' => 'sometimes|integer|min:0|max:86400',
            'agent_strategy' => 'sometimes|array:audience,objective,hashtags,target_accounts,search_queries,exclusions',
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
        if (isset($data['agent_strategy'])) {
            $data['agent_strategy'] = array_replace($account->agent_strategy ?? [], $data['agent_strategy']);
        }
        $account->update($data);
    }
}
