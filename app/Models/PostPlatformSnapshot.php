<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostPlatformSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'post_platform_id',
        'measured_at',
        'views',
        'likes',
        'comments',
        'shares',
        'bookmarks',
        'followers',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
        ];
    }

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }
}
