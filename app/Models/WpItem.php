<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WpItem extends Model
{
    protected $fillable = [
        'wp_source_id',
        'wp_post_id',
        'title',
        'url',
        'content',
        'summary',
        'author',
        'image_url',
        'post_type',
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

    public function wpSource(): BelongsTo
    {
        return $this->belongsTo(WpSource::class);
    }

    public function wpPosts(): HasMany
    {
        return $this->hasMany(WpPost::class);
    }
}
