<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WpSource extends Model
{
    protected $fillable = [
        'name',
        'url',
        'description',
        'auth_username',
        'auth_password',
        'post_types',
        'categories',
        'schedule_frequency',
        'schedule_time',
        'is_active',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'post_types' => 'array',
            'categories' => 'array',
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
            'auth_password' => 'encrypted',
        ];
    }

    public function wpItems(): HasMany
    {
        return $this->hasMany(WpItem::class);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'wp_source_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
