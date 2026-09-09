<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdsBoost extends Model
{
    protected $fillable = [
        'post_platform_id', 'user_id', 'platform', 'object_story_id', 'instagram_media_id',
        'campaign_id', 'adset_id', 'creative_id', 'ad_id',
        'budget', 'days', 'starts_at', 'ends_at', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
