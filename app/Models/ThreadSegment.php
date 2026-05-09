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
        'translations',
        'media',
        'is_boost',
        'boost_source_thread_id',
        'boost_source_url',
    ];

    protected function casts(): array
    {
        return [
            'platform_contents' => 'array',
            'translations' => 'array',
            'media' => 'array',
            'is_boost' => 'boolean',
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

    public function boostSourceThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'boost_source_thread_id');
    }

    public function getContentForPlatform(string $slug): string
    {
        $contents = $this->platform_contents ?? [];

        return ! empty($contents[$slug]) ? $contents[$slug] : $this->content_fr;
    }
}
