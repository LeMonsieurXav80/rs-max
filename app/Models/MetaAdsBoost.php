<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdsBoost extends Model
{
    protected $fillable = [
        'post_platform_id', 'user_id', 'meta_audience_id', 'name',
        'platform', 'object_story_id', 'instagram_media_id',
        'objective', 'optimization_goal', 'billing_event', 'bid_strategy', 'bid_amount',
        'campaign_id', 'adset_id', 'creative_id', 'ad_id',
        'budget', 'budget_type', 'days', 'starts_at', 'ends_at', 'targeting', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'bid_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            // Copie figee du ciblage envoye a Meta : l'audience aura pu changer
            // depuis, le ciblage reellement diffuse serait sinon perdu.
            'targeting' => 'array',
        ];
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(MetaAudience::class, 'meta_audience_id');
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
