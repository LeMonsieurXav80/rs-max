<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miroir d'une publication native, faite hors RS-Max, telle que la renvoie l'API
 * du reseau. Sert a deux choses : alimenter les stats, et etre « adoptee » —
 * fusionnee avec ses jumelles des autres reseaux en une publication RS-Max.
 */
class ExternalPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'platform_id',
        'external_id',
        'content',
        'media_url',
        'media',
        'post_url',
        'published_at',
        'metrics',
        'metrics_synced_at',
        'adopted_post_id',
        'adopted_at',
        'ignored_at',
        'group_key',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
            'adopted_at' => 'datetime',
            'ignored_at' => 'datetime',
            'metrics' => 'array',
            'media' => 'array',
        ];
    }

    /**
     * Get the social account that owns this external post.
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Get the platform this post was published on.
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * La publication RS-Max nee de l'adoption de cette carte, si elle existe.
     */
    public function adoptedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'adopted_post_id');
    }

    public function isAdopted(): bool
    {
        return $this->adopted_post_id !== null;
    }

    /**
     * Exclut les publications emises PAR RS-Max : elles remontent aussi dans
     * l'import, et les proposer a l'adoption creerait un doublon du Post existant.
     */
    public function scopeNotPublishedByRsMax(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('post_platform')
                ->whereColumn('post_platform.platform_id', 'external_posts.platform_id')
                ->whereColumn('post_platform.external_id', 'external_posts.external_id');
        });
    }

    /**
     * Les cartes qui ont vocation a apparaitre dans le flux d'adoption.
     */
    public function scopeAdoptable(Builder $query): Builder
    {
        return $query->whereNull('adopted_post_id')
            ->whereNull('ignored_at')
            ->notPublishedByRsMax();
    }

    /**
     * Medias de la publication, normalises en {url, type, thumbnail_url}.
     * Retombe sur `media_url` pour les lignes importees avant l'ajout de `media`.
     */
    public function mediaItems(): array
    {
        $items = $this->media;

        if (empty($items) && $this->media_url) {
            $items = [['url' => $this->media_url, 'type' => 'image']];
        }

        return self::normalizeMediaItems($items ?? []);
    }

    /**
     * @param  array  $items  Items bruts issus d'un service d'import.
     */
    public static function normalizeMediaItems(array $items): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            $url = $item['url'] ?? null;

            if (! is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $normalized[] = [
                'url' => $url,
                'type' => ($item['type'] ?? 'image') === 'video' ? 'video' : 'image',
                'thumbnail_url' => $item['thumbnail_url'] ?? null,
                'external_media_id' => $item['external_media_id'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Vignette a afficher dans le flux.
     */
    public function thumbnailUrl(): ?string
    {
        $first = $this->mediaItems()[0] ?? null;

        if (! $first) {
            return null;
        }

        return $first['thumbnail_url'] ?? $first['url'];
    }

    /**
     * Get formatted metrics for display.
     */
    public function getFormattedMetrics(): array
    {
        $metrics = $this->metrics ?? [];

        return [
            'views' => number_format($metrics['views'] ?? 0),
            'likes' => number_format($metrics['likes'] ?? 0),
            'comments' => number_format($metrics['comments'] ?? 0),
            // `shares` est absent chez plusieurs reseaux (et null chez YouTube) :
            // la cle n'est pas garantie, contrairement aux trois autres.
            'shares' => ! empty($metrics['shares']) ? number_format($metrics['shares']) : null,
        ];
    }
}
