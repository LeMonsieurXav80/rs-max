<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YtItem extends Model
{
    protected $fillable = [
        'yt_source_id',
        'video_id',
        'title',
        'url',
        'description',
        'thumbnail_url',
        'duration',
        'view_count',
        'like_count',
        'published_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'view_count' => 'integer',
            'like_count' => 'integer',
        ];
    }

    public function ytSource(): BelongsTo
    {
        return $this->belongsTo(YtSource::class);
    }

    public function ytPosts(): HasMany
    {
        return $this->hasMany(YtPost::class);
    }
}
