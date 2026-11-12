<?php

namespace App\Services\Agent;

use App\Models\User;
use App\Models\VaultSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Une seule validation pour l’écran /vault-sources et l’API : listes en texte (une ligne par entrée) ou en tableau. */
class VaultSourceInput
{
    /** @return array{0: array<string, mixed>, 1: ?list<int>} attributs, puis comptes (null si non fournis) */
    public static function validate(Request $request, User $user, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => "{$required}|string|max:120",
            'corpus' => [$required, 'string', 'regex:/^[a-z][a-z0-9_-]{0,30}$/'],
            'format' => [$required, Rule::in(array_keys(VaultSource::FORMATS))],
            'folders' => "{$required}",
            'excluded_folders' => 'nullable',
            'brands' => 'nullable',
            'is_active' => 'sometimes|boolean',
            'accounts' => 'sometimes|nullable|array',
            'accounts.*' => 'integer',
        ]);
        $attributes = array_intersect_key($data, array_flip(['name', 'corpus', 'format']));
        foreach (['folders' => 30, 'excluded_folders' => 30, 'brands' => 20] as $field => $max) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $values = self::lines($data[$field]);
            if ($values === null || count($values) > $max) {
                throw ValidationException::withMessages([$field => "Indiquez au plus {$max} entrées, une par ligne ou en tableau."]);
            }
            $attributes[$field] = $values;
        }
        if (array_key_exists('folders', $attributes)) {
            $refused = array_values(array_filter($attributes['folders'], fn ($folder) => VaultSource::forbidden($folder)));
            if ($refused !== [] || $attributes['folders'] === []) {
                throw ValidationException::withMessages(['folders' => $refused !== []
                    ? 'Dossiers refusés (zone sensible) : '.implode(', ', $refused).'.'
                    : 'Indiquez au moins un dossier.']);
            }
        }
        if ($request->has('is_active')) {
            $attributes['is_active'] = $request->boolean('is_active');
        }
        $accounts = null;
        if ($request->has('accounts')) {
            $ids = array_map('intval', (array) $request->input('accounts', []));
            $accounts = $user->visibleSocialAccounts()->whereIn('social_accounts.id', $ids)->pluck('social_accounts.id')->all();
            $unknown = array_values(array_diff($ids, $accounts));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['accounts' => 'Comptes inconnus : '.implode(', ', $unknown).'.']);
            }
        }

        return [$attributes, $accounts];
    }

    private static function lines(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_array($value) ? $value : (is_string($value) ? preg_split('/\R/', $value) : null);
        if ($items === null || collect($items)->contains(fn ($item) => ! is_string($item) || mb_strlen($item) > 200)) {
            return null;
        }

        return collect($items)->map(fn ($item) => trim($item, " \t/"))->filter()->unique()->values()->all();
    }
}
