<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPublication extends Model
{
    protected $fillable = [
        'media_file_id',
        'via_media_file_id',
        'post_id',
        'thread_segment_id',
        'post_platform_id',
        'social_account_id',
        'wp_source_id',
        'wp_post_id',
        'wp_attachment_id',
        'match_method',
        'match_confidence',
        'external_url',
        'published_at',
        'context',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'wp_post_id' => 'integer',
        'wp_attachment_id' => 'integer',
        'match_confidence' => 'integer',
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * Image générée par laquelle cette photo a été publiée (slide de carrousel).
     * Null pour une publication directe du fichier lui-même.
     */
    public function via(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'via_media_file_id');
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

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function wpSource(): BelongsTo
    {
        return $this->belongsTo(WpSource::class);
    }
}
