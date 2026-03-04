<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxItem extends Model
{
    protected $fillable = [
        'social_account_id',
        'platform_id',
        'type',
        'external_id',
        'external_post_id',
        'parent_id',
        'author_name',
        'author_username',
        'author_avatar_url',
        'author_external_id',
        'content',
        'post_url',
        'posted_at',
        'status',
        'reply_content',
        'reply_external_id',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
