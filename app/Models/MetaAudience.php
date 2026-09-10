<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une audience reutilisable, traduite en `targeting` Meta a la demande.
 *
 * Forme attendue de `spec` (toutes les cles sont facultatives sauf le pays) :
 *
 *   countries              ['PT','FR']            codes ISO-2
 *   regions                [{key,name}]           cles Meta (adgeolocation)
 *   cities                 [{key,name,radius,distance_unit}]
 *   excluded_countries     ['ES']
 *   age_min / age_max      13..65
 *   genders                [] tous | [1] hommes | [2] femmes
 *   locales                [{key,name}]           langues (adlocale)
 *   interests              [{id,name}]            centres d'interet
 *   behaviors              [{id,name}]
 *   excluded_interests     [{id,name}]
 *   custom_audiences       [{id,name}]
 *   excluded_custom_audiences [{id,name}]
 *   publisher_platforms    ['facebook','instagram']  vide = automatique
 *   facebook_positions / instagram_positions / device_platforms
 *   advantage_audience     bool — laisse Meta elargir au-dela du ciblage
 *
 * On garde `name` a cote des identifiants partout : Meta n'a besoin que de
 * l'id, mais une audience relue six mois plus tard sans libelle est illisible.
 */
class MetaAudience extends Model
{
    protected $fillable = [
        'user_id', 'name', 'description', 'spec', 'is_default',
        'estimated_reach', 'estimated_at',
    ];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'is_default' => 'boolean',
            'estimated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Une seule audience par defaut : poser le drapeau retire l'ancien.
     */
    public function markAsDefault(): void
    {
        static::where('id', '!=', $this->id)->where('is_default', true)->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }

    /**
     * Traduit la spec RS-Max en `targeting` Meta.
     *
     * Deux pieges tenus ici :
     *   - `flexible_spec` et non `interests` a la racine : la forme a plat est
     *     depreciee et se comporte differemment (ET au lieu de OU) ;
     *   - une categorie speciale (logement, credit, emploi, politique) INTERDIT
     *     le ciblage par age, genre, centres d'interet et geo fine. On nettoie
     *     ici plutot que de laisser Graph rendre un « Invalid parameter » qui
     *     n'explique rien.
     *
     * @return array<string,mixed>
     */
    public function toTargetingSpec(bool $specialCategory = false): array
    {
        $spec = $this->spec ?? [];

        $geo = array_filter([
            'countries' => $this->values($spec, 'countries'),
            'regions' => $this->keyed($spec, 'regions'),
            'cities' => array_map(fn ($c) => array_filter([
                'key' => $c['key'] ?? null,
                'radius' => $c['radius'] ?? null,
                'distance_unit' => $c['distance_unit'] ?? 'kilometer',
            ], fn ($v) => $v !== null), $spec['cities'] ?? []),
        ]);

        $targeting = ['geo_locations' => $geo ?: ['countries' => ['PT']]];

        if ($excluded = $this->values($spec, 'excluded_countries')) {
            $targeting['excluded_geo_locations'] = ['countries' => $excluded];
        }

        if ($locales = array_column($spec['locales'] ?? [], 'key')) {
            $targeting['locales'] = array_map('intval', $locales);
        }

        foreach (['custom_audiences', 'excluded_custom_audiences'] as $key) {
            if ($ids = $this->identified($spec, $key)) {
                $targeting[$key] = $ids;
            }
        }

        if ($platforms = $this->values($spec, 'publisher_platforms')) {
            $targeting['publisher_platforms'] = $platforms;

            // Meta refuse une position dont la regie n'est pas selectionnee.
            foreach (['facebook_positions' => 'facebook', 'instagram_positions' => 'instagram'] as $field => $platform) {
                if (in_array($platform, $platforms, true) && $positions = $this->values($spec, $field)) {
                    $targeting[$field] = $positions;
                }
            }
        }

        if ($devices = $this->values($spec, 'device_platforms')) {
            $targeting['device_platforms'] = $devices;
        }

        if ($specialCategory) {
            // Age legal impose par Meta sur les categories speciales.
            $targeting['age_min'] = 18;
            $targeting['age_max'] = 65;

            return $targeting;
        }

        $targeting['age_min'] = (int) ($spec['age_min'] ?? 18);
        $targeting['age_max'] = (int) ($spec['age_max'] ?? 65);

        if ($genders = $this->values($spec, 'genders')) {
            $targeting['genders'] = array_map('intval', $genders);
        }

        // OU entre les centres d'interet et les comportements du meme bloc ;
        // un second bloc dans flexible_spec ferait un ET, qu'on n'expose pas.
        $flexible = array_filter([
            'interests' => $this->identified($spec, 'interests'),
            'behaviors' => $this->identified($spec, 'behaviors'),
        ]);

        if ($flexible) {
            $targeting['flexible_spec'] = [$flexible];
        }

        if ($exclusions = $this->identified($spec, 'excluded_interests')) {
            $targeting['exclusions'] = ['interests' => $exclusions];
        }

        // Explicite dans les deux sens : sur certains comptes, ne pas envoyer le
        // champ laisse Meta activer l'elargissement automatique tout seul.
        $targeting['targeting_automation'] = ['advantage_audience' => ! empty($spec['advantage_audience']) ? 1 : 0];

        return $targeting;
    }

    /**
     * Resume lisible, pour les listes et les plans de campagne.
     */
    public function summary(): string
    {
        $spec = $this->spec ?? [];
        $parts = [];

        if ($countries = $this->values($spec, 'countries')) {
            $parts[] = implode(', ', $countries);
        }

        if ($cities = array_column($spec['cities'] ?? [], 'name')) {
            $parts[] = implode(', ', $cities);
        }

        $parts[] = ($spec['age_min'] ?? 18).'-'.($spec['age_max'] ?? 65).' ans';

        $genders = $this->values($spec, 'genders');
        if ($genders === [1]) {
            $parts[] = 'hommes';
        } elseif ($genders === [2]) {
            $parts[] = 'femmes';
        }

        if ($interests = array_column($spec['interests'] ?? [], 'name')) {
            $parts[] = count($interests).' centre(s) d\'intérêt : '.implode(', ', array_slice($interests, 0, 3))
                .(count($interests) > 3 ? '…' : '');
        }

        $parts[] = ($platforms = $this->values($spec, 'publisher_platforms'))
            ? implode(' + ', $platforms)
            : 'placements automatiques';

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<int,mixed>
     */
    private function values(array $spec, string $key): array
    {
        return array_values(array_filter((array) ($spec[$key] ?? []), fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Ne garde que les `{id}` : Meta ignore le libelle, et le renvoyer expose
     * a un refus quand le nom a change de son cote.
     *
     * @param  array<string,mixed>  $spec
     * @return array<int,array{id:string}>
     */
    private function identified(array $spec, string $key): array
    {
        return array_values(array_map(
            fn ($item) => ['id' => (string) ($item['id'] ?? $item)],
            array_filter((array) ($spec[$key] ?? []), fn ($item) => ! empty($item['id'] ?? $item)),
        ));
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<int,array{key:string}>
     */
    private function keyed(array $spec, string $key): array
    {
        return array_values(array_map(
            fn ($item) => ['key' => (string) ($item['key'] ?? $item)],
            array_filter((array) ($spec[$key] ?? []), fn ($item) => ! empty($item['key'] ?? $item)),
        ));
    }
}
