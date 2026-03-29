<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinterestPin extends Model
{
    protected $fillable = [
        'pinterest_feed_id',
        'source_type',
        'source_id',
        'guid',
        'version',
        'title_original',
        'title_generated',
        'description',
        'link_url',
        'source_image_url',
        'generated_image_path',
        'template',
        'status',
        'error_message',
        'added_to_feed_at',
        'published_at',
    ];

    protected $casts = [
        'added_to_feed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function feed(): BelongsTo
    {
        return $this->belongsTo(PinterestFeed::class, 'pinterest_feed_id');
    }

    public function getGeneratedImageUrlAttribute(): ?string
    {
        if (! $this->generated_image_path) {
            return null;
        }

        return url('/storage/' . $this->generated_image_path);
    }
}
