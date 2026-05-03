<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSearchTerm extends Model
{
    public const PURPOSE_LIKES = 'likes';

    public const PURPOSE_COMMENTS = 'comments';

    public const PURPOSE_FOLLOW = 'follow';

    protected $fillable = [
        'social_account_id',
        'term',
        'purpose',
        'is_active',
        'max_likes_per_run',
        'max_per_run',
        'like_replies',
        'last_run_at',
    ];

    protected $attributes = [
        'purpose' => self::PURPOSE_LIKES,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'like_replies' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function scopePurpose(\Illuminate\Database\Eloquent\Builder $query, string $purpose): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * Limite effective d'actions par run pour ce terme.
     * Pour les likes, on conserve la compat avec max_likes_per_run.
     */
    public function effectiveMaxPerRun(): int
    {
        if ($this->purpose === self::PURPOSE_LIKES) {
            return (int) ($this->max_per_run ?: $this->max_likes_per_run ?: 10);
        }

        return (int) ($this->max_per_run ?: 5);
    }
}
