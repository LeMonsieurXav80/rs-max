<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotActionLog extends Model
{
    protected $fillable = [
        'social_account_id',
        'action_type',
        'source',
        'target_uri',
        'target_author',
        'target_text',
        'search_term',
        'success',
        'error',
        'metadata',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'metadata' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
