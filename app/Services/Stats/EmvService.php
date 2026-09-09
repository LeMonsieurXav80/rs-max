<?php

namespace App\Services\Stats;

use App\Models\Setting;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Facades\Log;

/**
 * Valorisation des retombees (EMV — Earned Media Value).
 *
 * Le calcul est TOUJOURS a la volee, jamais persiste : les tarifs de reference
 * bougent, et un montant stocke serait fige sur un CPM perime. Voir config/emv.php.
 *
 * Les fils de discussion sont hors perimetre : `thread_segment_platform` ne porte
 * aucune metrique, il n'y a donc rien a valoriser (et rien a double-compter).
 */
class EmvService
{
    /** Cle de surcharge des tarifs en base (JSON), editable depuis /settings. */
    public const SETTING_KEY = 'emv_rates';

    /** `reference` = CPM de config ; `meta_observed` = CPM paye sur Meta Ads. */
    public const SOURCE_KEY = 'emv_cpm_source';

    /** metrique normalisee (post_platform.metrics) => action tarifee. */
    private const METRIC_TO_ACTION = [
        'views' => 'view',
        'likes' => 'like',
        'comments' => 'comment',
        'shares' => 'share',
        'bookmarks' => 'bookmark',
    ];

    /** @var array<string,mixed>|null */
    private ?array $rates = null;

    public function __construct(private readonly MetaAdsService $metaAds) {}

    /**
     * Valorise un releve de metriques unique.
     *
     * @param  array<string,mixed>  $metrics
     * @return array{cpm:float|null,ayzenberg:float,views:int,views_available:bool}
     */
    public function forMetrics(?string $platformSlug, array $metrics): array
    {
        $rates = $this->ratesFor($platformSlug);
        $views = (int) ($metrics['views'] ?? 0);

        // Sans vues exposees par l'API, la methode CPM n'est pas « zero » :
        // elle n'est pas calculable. On rend null pour que l'appelant puisse
        // afficher une couverture honnete plutot qu'un total qui ment.
        $cpm = $rates['views_available'] ? round($views / 1000 * $rates['cpm'], 2) : null;

        $ayzenberg = 0.0;
        foreach (self::METRIC_TO_ACTION as $metric => $action) {
            $count = (int) ($metrics[$metric] ?? 0);

            if ($count > 0) {
                $ayzenberg += $count * ($rates['actions'][$action] ?? 0.0);
            }
        }

        return [
            'cpm' => $cpm,
            'ayzenberg' => round($ayzenberg, 2),
            'views' => $views,
            'views_available' => $rates['views_available'],
            'cpm_source' => $rates['cpm_source'],
        ];
    }

    /**
     * Agrege un ensemble de diffusions (PostPlatform ou ExternalPost).
     *
     * Tout objet portant `metrics` (tableau) et la relation `platform` convient.
     *
     * @param  iterable<object>  $items
     * @return array{
     *     currency:string,
     *     cpm:float,
     *     ayzenberg:float,
     *     total:float,
     *     by_platform:array<int,array<string,mixed>>,
     *     coverage:array{items:int,valued:int,measurable:int,uncovered_platforms:array<int,string>}
     * }
     */
    public function forItems(iterable $items): array
    {
        $cpmTotal = 0.0;
        $ayzenbergTotal = 0.0;
        $byPlatform = [];
        $count = 0;
        $valued = 0;
        $measurable = 0;
        $uncovered = [];

        foreach ($items as $item) {
            $metrics = $item->metrics ?? null;

            if (! is_array($metrics)) {
                continue;
            }

            $count++;
            $slug = $item->platform?->slug;
            $emv = $this->forMetrics($slug, $metrics);
            $key = $slug ?? 'inconnu';

            $byPlatform[$key] ??= [
                'slug' => $key,
                'items' => 0,
                'views' => 0,
                'cpm' => 0.0,
                'ayzenberg' => 0.0,
                'views_available' => $emv['views_available'],
                'cpm_source' => $emv['cpm_source'],
            ];

            $byPlatform[$key]['items']++;
            $byPlatform[$key]['views'] += $emv['views'];
            $byPlatform[$key]['ayzenberg'] = round($byPlatform[$key]['ayzenberg'] + $emv['ayzenberg'], 2);
            $ayzenbergTotal += $emv['ayzenberg'];

            if ($emv['cpm'] === null) {
                $uncovered[$key] = true;
            } else {
                $measurable++;
                $byPlatform[$key]['cpm'] = round($byPlatform[$key]['cpm'] + $emv['cpm'], 2);
                $cpmTotal += $emv['cpm'];

                if ($emv['views'] > 0) {
                    $valued++;
                }
            }
        }

        $cpmTotal = round($cpmTotal, 2);
        $ayzenbergTotal = round($ayzenbergTotal, 2);

        uasort($byPlatform, fn ($a, $b) => ($b['cpm'] + $b['ayzenberg']) <=> ($a['cpm'] + $a['ayzenberg']));

        return [
            'currency' => $this->currency(),
            'cpm' => $cpmTotal,
            'ayzenberg' => $ayzenbergTotal,
            // Les deux methodes ne se recouvrent pas (la vue vaut 0 cote
            // Ayzenberg par defaut) : le cumul est la valorisation complete.
            'total' => round($cpmTotal + $ayzenbergTotal, 2),
            'by_platform' => array_values($byPlatform),
            'coverage' => [
                'items' => $count,
                // Diffusions ou la methode CPM a effectivement produit un montant.
                'valued' => $valued,
                // Diffusions sur un reseau qui expose des vues (mesurables).
                'measurable' => $measurable,
                'uncovered_platforms' => array_keys($uncovered),
            ],
        ];
    }

    /**
     * Tarifs effectifs d'une plateforme : defauts, surcharge config, surcharge base.
     *
     * @return array{cpm:float,views_available:bool,actions:array<string,float>}
     */
    public function ratesFor(?string $platformSlug): array
    {
        return $this->resolve($this->rates(), $platformSlug);
    }

    /**
     * Tarifs de config/emv.php, surcharges en base ignorees.
     *
     * Sert de reference pour ne persister que les ecarts : comparer a
     * ratesFor() ferait disparaitre une surcharge re-soumise a l'identique.
     *
     * @return array{cpm:float,views_available:bool,actions:array<string,float>}
     */
    public function defaultRatesFor(?string $platformSlug): array
    {
        return $this->resolve(config('emv'), $platformSlug);
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array{cpm:float,views_available:bool,actions:array<string,float>}
     */
    private function resolve(array $source, ?string $platformSlug): array
    {
        $defaults = $source['defaults'];
        $platform = $platformSlug ? ($source['platforms'][$platformSlug] ?? []) : [];

        return [
            'cpm' => (float) ($platform['cpm'] ?? $defaults['cpm']),
            'views_available' => (bool) ($platform['views_available'] ?? true),
            // D'ou vient le CPM : un chiffre presente a un partenaire doit
            // pouvoir dire s'il vient d'un bareme ou d'une depense reelle.
            'cpm_source' => $platform['cpm_source'] ?? 'reference',
            'actions' => array_map(
                fn ($v) => (float) $v,
                array_merge($defaults['actions'], $platform['actions'] ?? []),
            ),
        ];
    }

    /**
     * Remplace le CPM de bareme par le CPM reellement paye sur Meta Ads.
     *
     * Ne touche que les regies que Meta ventile (Facebook, Instagram, Threads) :
     * les autres reseaux gardent leur tarif de reference.
     *
     * @param  array<string,mixed>  $platforms
     * @return array<string,mixed>
     */
    private function applyObservedCpm(array $platforms, string $currency): array
    {
        if (Setting::get(self::SOURCE_KEY, 'reference') !== 'meta_observed') {
            return $platforms;
        }

        $observed = $this->metaAds->observedCpm();

        if (! $observed['available']) {
            return $platforms;
        }

        // Un CPM en USD applique a une valorisation en EUR donnerait un montant
        // faux sans le dire. Devises differentes : on garde le bareme.
        if ($observed['currency'] && $observed['currency'] !== $currency) {
            Log::warning('EMV : CPM Meta ignoré, devise du compte publicitaire différente', [
                'compte' => $observed['currency'],
                'emv' => $currency,
            ]);

            return $platforms;
        }

        foreach ($observed['platforms'] as $slug => $data) {
            $platforms[$slug] = array_merge($platforms[$slug] ?? [], [
                'cpm' => $data['cpm'],
                'cpm_source' => 'meta_observed',
            ]);
        }

        return $platforms;
    }

    public function currency(): string
    {
        return $this->rates()['currency'];
    }

    /**
     * Tarifs par plateforme, prets a etre edites dans un formulaire.
     *
     * @return array<string,array{cpm:float,views_available:bool,actions:array<string,float>}>
     */
    public function allRates(): array
    {
        $slugs = array_keys($this->rates()['platforms']);
        sort($slugs);

        return array_combine($slugs, array_map(fn ($s) => $this->ratesFor($s), $slugs));
    }

    /**
     * Enregistre une surcharge de tarifs et purge le cache d'instance.
     *
     * @param  array<string,mixed>|null  $overrides  null remet les valeurs de config/emv.php
     */
    public function saveOverrides(?array $overrides): void
    {
        Setting::set(self::SETTING_KEY, $overrides === null ? null : json_encode($overrides));
        $this->rates = null;
    }

    /**
     * @return array{currency:string,defaults:array<string,mixed>,platforms:array<string,mixed>}
     */
    private function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $config = config('emv');
        $overrides = $this->overrides();

        $platforms = $config['platforms'] ?? [];
        foreach (($overrides['platforms'] ?? []) as $slug => $values) {
            if (is_array($values)) {
                // array_merge et non replace_recursive : une surcharge partielle
                // des actions doit completer les defauts, pas les remplacer.
                $platforms[$slug] = array_merge($platforms[$slug] ?? [], $values);

                if (isset($values['actions'])) {
                    $platforms[$slug]['actions'] = array_merge(
                        $config['platforms'][$slug]['actions'] ?? [],
                        $values['actions'],
                    );
                }
            }
        }

        $currency = $overrides['currency'] ?? $config['currency'];

        return $this->rates = [
            'currency' => $currency,
            'defaults' => [
                'cpm' => (float) ($overrides['defaults']['cpm'] ?? $config['defaults']['cpm']),
                'actions' => array_merge(
                    $config['defaults']['actions'],
                    $overrides['defaults']['actions'] ?? [],
                ),
            ],
            // En dernier : le CPM reellement paye prime sur le bareme.
            'platforms' => $this->applyObservedCpm($platforms, $currency),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        $raw = Setting::get(self::SETTING_KEY);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
