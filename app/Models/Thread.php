<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Thread extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'source_url',
        'source_url_hash',
        'source_type',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Thread $thread) {
            $thread->source_url_hash = $thread->source_url
                ? hash('sha256', $thread->source_url)
                : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ThreadSegment::class)->orderBy('position');
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'thread_social_account')
            ->withPivot('platform_id', 'publish_mode', 'status')
            ->withTimestamps();
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')->whereNotNull('scheduled_at');
    }

    public function scopeReadyToPublish($query)
    {
        return $query->scheduled()->where('scheduled_at', '<=', now());
    }

    public static function findBySourceUrl(string $url): ?self
    {
        return static::where('source_url_hash', hash('sha256', $url))->first();
    }

    public function getContentPreviewAttribute(): string
    {
        return Str::limit($this->segments->first()?->content_fr, 100);
    }

    public function getSegmentCountAttribute(): int
    {
        return $this->segments->count();
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'scheduled' => 'blue',
            'publishing' => 'yellow',
            'published' => 'green',
            'failed' => 'red',
            'partial' => 'orange',
            default => 'gray',
        };
    }
}
