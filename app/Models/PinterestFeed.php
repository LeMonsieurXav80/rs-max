<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PinterestFeed extends Model
{
    protected $fillable = [
        'social_account_id',
        'name',
        'slug',
        'board_id',
        'board_name',
        'template',
        'colors',
        'language',
        'max_items',
        'items_per_day',
        'is_active',
    ];

    protected $casts = [
        'colors' => 'array',
        'is_active' => 'boolean',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(PinterestPin::class);
    }

    public function getFeedUrlAttribute(): string
    {
        return url("/feeds/pinterest/{$this->slug}.xml");
    }
}
