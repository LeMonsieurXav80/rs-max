<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'platform_id',
        'external_id',
        'content',
        'media_url',
        'post_url',
        'published_at',
        'metrics',
        'metrics_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    /**
     * Get the social account that owns this external post.
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Get the platform this post was published on.
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * Get formatted metrics for display.
     */
    public function getFormattedMetrics(): array
    {
        $metrics = $this->metrics ?? [];

        return [
            'views' => number_format($metrics['views'] ?? 0),
            'likes' => number_format($metrics['likes'] ?? 0),
            'comments' => number_format($metrics['comments'] ?? 0),
            'shares' => $metrics['shares'] ? number_format($metrics['shares']) : null,
        ];
    }
}
