<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'platform_id',
        'platform_account_id',
        'name',
        'profile_picture_url',
        'followers_count',
        'followers_synced_at',
        'credentials',
        'languages',
        'branding',
        'show_branding',
        'is_active',
        'persona_id',
        'last_used_at',
        'last_history_import_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'languages' => 'array',
            'is_active' => 'boolean',
            'show_branding' => 'boolean',
            'last_used_at' => 'datetime',
            'last_history_import_at' => 'datetime',
            'followers_synced_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }

    public function externalPosts(): HasMany
    {
        return $this->hasMany(ExternalPost::class);
    }

    public function rssFeeds(): BelongsToMany
    {
        return $this->belongsToMany(RssFeed::class, 'rss_feed_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
