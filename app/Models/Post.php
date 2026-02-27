<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'content_fr',
        'content_en',
        'translations',
        'hashtags',
        'auto_translate',
        'media',
        'link_url',
        'location_name',
        'location_id',
        'status',
        'source_type',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'translations' => 'array',
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

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')->whereNotNull('scheduled_at');
    }

    public function scopeReadyToPublish($query)
    {
        return $query->scheduled()->where('scheduled_at', '<=', now());
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
