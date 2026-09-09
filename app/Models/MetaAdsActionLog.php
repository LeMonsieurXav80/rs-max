<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdsActionLog extends Model
{
    protected $fillable = [
        'user_id',
        'object_type',
        'object_id',
        'object_name',
        'action',
        'previous',
        'requested',
        'dry_run',
        'success',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'requested' => 'array',
            'dry_run' => 'boolean',
            'success' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
