<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'content_fr',
        'content_en',
        'article_title',
        'translations',
        'platform_contents',
        'hashtags',
        'auto_translate',
        'media',
        'link_url',
        'location_name',
        'location_id',
        'status',
        'source_type',
        'reshare_source_post_platform_id',
        'reshare_source_platform_id',
        'reshare_source_external_id',
        'reshare_source_url',
        'reshare_mode',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'translations' => 'array',
            'platform_contents' => 'array',
            'auto_translate' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }

    /**
     * Partenaires tagues sur la publication (reporting interne).
     * pivot.source vaut 'auto' (herite d'une photo taguee, recalcule a chaque
     * enregistrement) ou 'manual' (pose a la main, jamais ecrase).
     */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'partner_post')
            ->withPivot('source')
            ->withTimestamps();
    }

    public function reshareSourcePostPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class, 'reshare_source_post_platform_id');
    }

    public function reshareSourcePlatform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'reshare_source_platform_id');
    }

    /**
     * Tous les posts qui repartagent une publication (post_platform) du post courant.
     */
    public function reshares()
    {
        return Post::query()
            ->whereIn('reshare_source_post_platform_id', $this->postPlatforms()->select('id'));
    }

    public function isReshare(): bool
    {
        return $this->reshare_source_post_platform_id !== null
            || $this->reshare_source_url !== null;
    }

    public function wpPost(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WpPost::class);
    }

    public function ytPost(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(YtPost::class);
    }

    public function rssPost(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RssPost::class);
    }

    public function redditPost(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RedditPost::class);
    }

    public function getSourceImageUrlAttribute(): ?string
    {
        if ($this->source_type === 'wordpress' && $this->relationLoaded('wpPost') && $this->wpPost?->wpItem) {
            return $this->wpPost->wpItem->image_url;
        }

        if ($this->source_type === 'youtube' && $this->relationLoaded('ytPost') && $this->ytPost?->ytItem) {
            return $this->ytPost->ytItem->thumbnail_url;
        }

        if ($this->source_type === 'rss' && $this->relationLoaded('rssPost') && $this->rssPost?->rssItem) {
            return $this->rssPost->rssItem->image_url;
        }

        if ($this->source_type === 'reddit' && $this->relationLoaded('redditPost') && $this->redditPost?->redditItem) {
            return $this->redditPost->redditItem->thumbnail_url;
        }

        return null;
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')->whereNotNull('scheduled_at');
    }

    public function scopeReadyToPublish($query)
    {
        return $query->scheduled()->where('scheduled_at', '<=', now());
    }

    public function getContentForPlatform(string $slug): string
    {
        $contents = $this->platform_contents ?? [];

        return ! empty($contents[$slug]) ? $contents[$slug] : $this->content_fr;
    }

    public function getContentPreviewAttribute(): string
    {
        return \Illuminate\Support\Str::limit($this->content_fr, 100);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'scheduled' => 'blue',
            'publishing' => 'yellow',
            'published' => 'green',
            'failed' => 'red',
            default => 'gray',
        };
    }
}
