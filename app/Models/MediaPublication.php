<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPublication extends Model
{
    protected $fillable = [
        'media_file_id',
        'post_id',
        'thread_segment_id',
        'post_platform_id',
        'external_url',
        'published_at',
        'context',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function threadSegment(): BelongsTo
    {
        return $this->belongsTo(ThreadSegment::class);
    }

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }
}
