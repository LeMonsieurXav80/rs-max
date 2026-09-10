<?php

namespace App\Services\Meta;

use App\Models\User;

/**
 * Les garde-fous, en un seul endroit.
 *
 * Ils vivaient dans le contrôleur d'API tant que celle-ci était le seul chemin
 * d'écriture. Depuis que l'interface sait créer une campagne, les dupliquer
 * reviendrait à ouvrir une porte dérobée : le jour où l'un des deux chemins
 * oublie un plafond, c'est une facture.
 *
 * Chaque méthode rend `null` quand c'est bon, ou un refus prêt à rendre :
 * `['status' => 4xx, 'error' => '…', 'hint' => '…']`.
 */
class MetaAdsGuard
{
    public function __construct(private readonly MetaAdsService $ads) {}

    /**
     * L'écriture est-elle ouverte, et par la bonne personne ?
     *
     * @return array<string,mixed>|null
     */
    public function write(?User $user): ?array
    {
        if (! $user?->isManager()) {
            return ['status' => 403, 'error' => 'Réservé aux managers.'];
        }

        if (! config('meta_ads.write_enabled')) {
            return [
                'status' => 403,
                'error' => 'Le pilotage en écriture des campagnes est désactivé.',
                'how_to_enable' => 'Passer META_ADS_WRITE_ENABLED=true — geste délibéré, ces appels dépensent de l\'argent réel.',
            ];
        }

        if (! $this->ads->isConfigured()) {
            return ['status' => 422, 'error' => 'Compte publicitaire Meta non configuré (voir /settings).'];
        }

        return null;
    }

    /**
     * Un budget peut-il passer de $current à $amount ?
     *
     * Deux barrières distinctes : le saut relatif (une erreur de raisonnement ou
     * d'unité se voit comme une hausse énorme) et le plafond absolu, qui lui
     * n'est jamais contournable — sinon ce n'est pas un plafond.
     *
     * @return array<string,mixed>|null
     */
    public function budget(float $amount, ?float $current, bool $lifetime, bool $force): ?array
    {
        $maxDaily = $this->ads->maxDailyBudget();

        if (! $lifetime && $amount > $maxDaily) {
            return [
                'status' => 422,
                'error' => "Budget quotidien demandé ({$amount}) au-dessus du plafond absolu ({$maxDaily}).",
                'hint' => 'Ce plafond n\'est pas contournable par `force`. Il se règle dans /settings, onglet Statistiques.',
            ];
        }

        if ($current === null || $current <= 0 || $force) {
            return null;
        }

        $maxIncrease = $this->ads->maxBudgetIncreasePct();
        $increase = ($amount - $current) / $current * 100;

        if ($increase > $maxIncrease) {
            return [
                'status' => 422,
                'error' => sprintf(
                    'Hausse de %.1f%% (%s → %s) au-dessus du maximum de %.0f%% en une fois.',
                    $increase,
                    $current,
                    $amount,
                    $maxIncrease,
                ),
                'hint' => 'Renvoyer avec `force: true` si la hausse est voulue, ou procéder par paliers.',
            ];
        }

        return null;
    }
}
