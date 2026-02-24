<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostLog extends Model
{
    protected $fillable = [
        'post_platform_id',
        'action',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }
}
