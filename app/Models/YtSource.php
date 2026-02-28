<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YtSource extends Model
{
    protected $fillable = [
        'name',
        'channel_url',
        'channel_id',
        'channel_name',
        'thumbnail_url',
        'schedule_frequency',
        'schedule_time',
        'is_active',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function ytItems(): HasMany
    {
        return $this->hasMany(YtItem::class);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'yt_source_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
