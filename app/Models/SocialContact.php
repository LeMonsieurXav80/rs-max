<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialContact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_read_at' => 'datetime', 'followed_at' => 'datetime',
            'unfollowed_at' => 'datetime', 'follow_back_checked_at' => 'datetime'];
    }
}
