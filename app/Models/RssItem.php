<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RssItem extends Model
{
    protected $fillable = [
        'rss_feed_id',
        'guid',
        'title',
        'url',
        'content',
        'summary',
        'author',
        'image_url',
        'published_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function rssFeed(): BelongsTo
    {
        return $this->belongsTo(RssFeed::class);
    }

    public function rssPosts(): HasMany
    {
        return $this->hasMany(RssPost::class);
    }
}
