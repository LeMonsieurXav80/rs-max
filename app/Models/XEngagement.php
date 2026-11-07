<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XEngagement extends Model
{
    protected $table = 'x_engagements';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'prepared'];

    public const ACTIONS = ['like', 'unlike', 'bookmark', 'unbookmark', 'repost', 'unrepost', 'reply', 'follow', 'unfollow', 'quote'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'requested_at' => 'datetime'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function browserTask(): BelongsTo
    {
        return $this->belongsTo(BrowserTask::class);
    }
}
