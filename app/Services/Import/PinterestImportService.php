<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Import\Concerns\ImportsIncrementally;
use App\Services\Pinterest\PinterestApiService;
use Illuminate\Support\Collection;

/**
 * Rapatrie les epingles creees sur Pinterest hors RS-Max.
 *
 * Pinterest n'est pas publiable depuis RS-Max (aucun adapter) : tout ce qui
 * existe sur le compte a donc ete fait nativement. C'est le reseau ou ce flux
 * sert le plus.
 *
 * Une epingle n'a pas de « texte » unique : un titre, une description, et un
 * lien sortant qui est souvent le vrai propos. On concatene titre et
 * description, ce qui donne au regroupement inter-reseaux de quoi reconnaitre
 * la meme publication partie ailleurs.
 */
class PinterestImportService implements PlatformImportInterface
{
    use ImportsIncrementally;

    public function __construct(private readonly PinterestApiService $api) {}

    public function importHistory(SocialAccount $account, int $limit = 50): Collection
    {
        $platform = Platform::where('slug', 'pinterest')->firstOrFail();
        $since = $this->importSince($account);

        $imported = collect();

        foreach ($this->api->getOwnedPins($account, $limit) as $pin) {
            $createdAt = $pin['created_at'] ?? null;

            // Flux rendu du plus recent au plus ancien.
            if ($this->isBeforeWindow($createdAt, $since)) {
                break;
            }

            $pinId = (string) ($pin['id'] ?? '');

            if ($pinId === '') {
                continue;
            }

            $metrics = $this->metricsFrom($pin);
            $media = $this->extractMedia($pin);
            $existing = $this->existingPost($platform->id, $pinId);

            if ($existing) {
                $existing->update([
                    'metrics' => $metrics,
                    'metrics_synced_at' => now(),
                    'media' => $media ?: $existing->media,
                ]);

                continue;
            }

            $imported->push(ExternalPost::create([
                'social_account_id' => $account->id,
                'platform_id' => $platform->id,
                'external_id' => $pinId,
                'content' => $this->contentFrom($pin),
                'media_url' => $media[0]['url'] ?? null,
                'media' => $media,
                'post_url' => "https://www.pinterest.com/pin/{$pinId}/",
                'published_at' => $createdAt,
                'metrics' => $metrics,
                'metrics_synced_at' => now(),
            ]));
        }

        $account->update(['last_history_import_at' => now()]);

        return $imported;
    }

    public function getQuotaCost(int $postCount): array
    {
        $calls = max(1, (int) ceil($postCount / 25));

        return [
            'cost' => $calls,
            'description' => "{$calls} requete(s) Pinterest, statistiques comprises",
        ];
    }

    /**
     * Titre et description mis bout a bout. Le lien sortant n'est pas repris :
     * il est rarement le message, et polluerait la comparaison de textes.
     */
    private function contentFrom(array $pin): ?string
    {
        $parts = array_filter([
            trim((string) ($pin['title'] ?? '')),
            trim((string) ($pin['description'] ?? '')),
        ]);

        return $parts ? implode("\n\n", $parts) : null;
    }

    /**
     * Pinterest expose plusieurs tailles d'une meme image : on prend la plus
     * large disponible, celle qui servira si l'epingle est adoptee.
     *
     * @return array<int, array{url: string, type: string, thumbnail_url: ?string, external_media_id: ?string}>
     */
    private function extractMedia(array $pin): array
    {
        $media = $pin['media'] ?? [];
        $isVideo = ($media['media_type'] ?? '') === 'video';

        if ($isVideo) {
            $cover = $media['cover_image_url'] ?? null;

            return $cover
                ? ExternalPost::normalizeMediaItems([[
                    'url' => $cover,
                    'type' => 'video',
                    'thumbnail_url' => $cover,
                    'external_media_id' => $pin['id'] ?? null,
                ]])
                : [];
        }

        $images = $media['images'] ?? [];

        if ($images === []) {
            return [];
        }

        $widest = null;
        $widestSize = -1;

        foreach ($images as $image) {
            $width = (int) ($image['width'] ?? 0);

            if ($width > $widestSize && ! empty($image['url'])) {
                $widest = $image['url'];
                $widestSize = $width;
            }
        }

        return $widest
            ? ExternalPost::normalizeMediaItems([[
                'url' => $widest,
                'type' => 'image',
                'external_media_id' => $pin['id'] ?? null,
            ]])
            : [];
    }

    /**
     * Pinterest ne compte pas comme les autres : un « save » est l'equivalent
     * du partage, une « reaction » celui du like.
     */
    private function metricsFrom(array $pin): array
    {
        $lifetime = $pin['pin_metrics']['lifetime_metrics'] ?? [];

        return [
            'views' => (int) ($lifetime['impression'] ?? 0),
            'likes' => (int) ($lifetime['reaction'] ?? 0),
            'comments' => (int) ($lifetime['comment'] ?? 0),
            'shares' => (int) ($lifetime['save'] ?? 0),
        ];
    }
}
