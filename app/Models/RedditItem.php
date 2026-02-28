<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedditItem extends Model
{
    protected $fillable = [
        'reddit_source_id',
        'reddit_post_id',
        'title',
        'url',
        'selftext',
        'author',
        'score',
        'num_comments',
        'permalink',
        'thumbnail_url',
        'is_self',
        'published_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'num_comments' => 'integer',
            'is_self' => 'boolean',
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function redditSource(): BelongsTo
    {
        return $this->belongsTo(RedditSource::class);
    }

    public function redditPosts(): HasMany
    {
        return $this->hasMany(RedditPost::class);
    }
}
