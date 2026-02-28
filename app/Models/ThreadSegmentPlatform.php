<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadSegmentPlatform extends Model
{
    protected $table = 'thread_segment_platform';

    protected $fillable = [
        'thread_segment_id',
        'social_account_id',
        'platform_id',
        'status',
        'external_id',
        'error_message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function threadSegment(): BelongsTo
    {
        return $this->belongsTo(ThreadSegment::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
