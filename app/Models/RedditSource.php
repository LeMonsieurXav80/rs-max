<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedditSource extends Model
{
    protected $fillable = [
        'name',
        'subreddit',
        'sort_by',
        'time_filter',
        'min_score',
        'schedule_frequency',
        'schedule_time',
        'is_active',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'min_score' => 'integer',
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function redditItems(): HasMany
    {
        return $this->hasMany(RedditItem::class);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'reddit_source_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
