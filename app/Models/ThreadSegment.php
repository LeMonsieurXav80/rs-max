<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThreadSegment extends Model
{
    protected $fillable = [
        'thread_id',
        'position',
        'content_fr',
        'platform_contents',
        'media',
    ];

    protected function casts(): array
    {
        return [
            'platform_contents' => 'array',
            'media' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function segmentPlatforms(): HasMany
    {
        return $this->hasMany(ThreadSegmentPlatform::class);
    }

    public function getContentForPlatform(string $slug): string
    {
        $contents = $this->platform_contents ?? [];

        return ! empty($contents[$slug]) ? $contents[$slug] : $this->content_fr;
    }
}
