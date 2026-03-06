<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotTargetAccount extends Model
{
    protected $fillable = [
        'social_account_id',
        'handle',
        'did',
        'status',
        'current_post_uri',
        'current_cursor',
        'likers_processed',
        'likes_given',
        'follows_given',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'likers_processed' => 'integer',
            'likes_given' => 'integer',
            'follows_given' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
