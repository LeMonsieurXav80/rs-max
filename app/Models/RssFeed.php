<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RssFeed extends Model
{
    protected $fillable = [
        'name',
        'url',
        'description',
        'category',
        'is_active',
        'is_multi_part_sitemap',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_multi_part_sitemap' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function rssItems(): HasMany
    {
        return $this->hasMany(RssItem::class);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'rss_feed_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
