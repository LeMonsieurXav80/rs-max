<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSearchTerm extends Model
{
    protected $fillable = [
        'social_account_id',
        'term',
        'is_active',
        'max_likes_per_run',
        'like_replies',
        'last_run_at',
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
}
