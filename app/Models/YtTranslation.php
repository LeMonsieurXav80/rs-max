<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YtTranslation extends Model
{
    protected $fillable = [
        'social_account_id',
        'video_id',
        'language',
        'type',
        'original_text',
        'translated_text',
        'status',
        'uploaded_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
