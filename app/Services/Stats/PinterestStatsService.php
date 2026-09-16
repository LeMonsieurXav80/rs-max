<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Services\Pinterest\PinterestApiService;
use Illuminate\Support\Facades\Log;

/**
 * Statistiques d'une epingle.
 *
 * Pinterest ne compte pas comme les autres : un « save » est l'equivalent du
 * partage, une « reaction » celui du like. Les clics (`pin_click`,
 * `outbound_click`) n'ont pas d'equivalent chez les autres reseaux et ne sont
 * donc pas remontes — la colonne `metrics` est un format commun.
 *
 * Mapping identique a celui de l'import, a dessein : les deux chemins doivent
 * produire les memes chiffres, sinon une publication changerait de valeur selon
 * qu'elle vient d'etre adoptee ou resynchronisee.
 */
class PinterestStatsService implements PlatformStatsInterface
{
    public function __construct(private readonly PinterestApiService $api) {}

    public function fetchMetrics(PostPlatform $postPlatform): ?array
    {
        $account = $postPlatform->socialAccount;
        $pinId = $postPlatform->external_id;

        if (! $account || ! $pinId) {
            return null;
        }

        $pin = $this->api->getPin($account, $pinId);

        if ($pin === null) {
            Log::error('PinterestStatsService: epingle illisible', [
                'post_platform_id' => $postPlatform->id,
                'pin_id' => $pinId,
            ]);

            return null;
        }

        $lifetime = $pin['pin_metrics']['lifetime_metrics'] ?? [];

        return [
            'views' => (int) ($lifetime['impression'] ?? 0),
            'likes' => (int) ($lifetime['reaction'] ?? 0),
            'comments' => (int) ($lifetime['comment'] ?? 0),
            'shares' => (int) ($lifetime['save'] ?? 0),
            // Le nombre d'abonnes ne se lit pas sur une epingle ; il vient de
            // la synchro des comptes.
            'followers' => null,
        ];
    }
}
