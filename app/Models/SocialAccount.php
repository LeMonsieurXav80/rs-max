<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'platform_id',
        'name',
        'credentials',
        'language',
        'branding',
        'show_branding',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'show_branding' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }
}
