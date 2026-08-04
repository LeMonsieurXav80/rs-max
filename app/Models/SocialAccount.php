<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $hidden = [
        'credentials',
    ];

    protected $fillable = [
        'platform_id',
        'platform_account_id',
        'subscription_type',
        'name',
        'profile_picture_url',
        'followers_count',
        'followers_synced_at',
        'credentials',
        'languages',
        'branding',
        'show_branding',
        'persona_id',
        'last_used_at',
        'last_history_import_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'languages' => 'array',
            'show_branding' => 'boolean',
            'last_used_at' => 'datetime',
            'last_history_import_at' => 'datetime',
            'followers_synced_at' => 'datetime',
        ];
    }

    /**
     * Abonnement payant sur la plateforme.
     *
     * X renvoie Basic | Premium | PremiumPlus via user.fields=subscription_type ;
     * seul « Basic » (ou l'absence de valeur) correspond à un compte gratuit.
     * C'est ce qui débloque les posts longs (25 000 caractères) et les Articles.
     */
    public function hasPaidSubscription(): bool
    {
        return $this->subscription_type !== null
            && strtolower($this->subscription_type) !== 'basic'
            && strtolower($this->subscription_type) !== 'none';
    }

    /**
     * Limite de caractères applicable à CE compte, et non à sa plateforme :
     * un compte X Premium publie jusqu'à 25 000 caractères là où un compte
     * gratuit est plafonné à 280.
     */
    public function charLimit(int $default): int
    {
        $slug = $this->platform?->slug;

        if ($slug === 'twitter' && $this->hasPaidSubscription()) {
            return (int) Setting::get('platform_char_limit_twitter_premium', 25000);
        }

        return (int) Setting::get("platform_char_limit_{$slug}", $default);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_active')->withTimestamps();
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }

    public function accountGroups(): BelongsToMany
    {
        return $this->belongsToMany(AccountGroup::class);
    }

    public function externalPosts(): HasMany
    {
        return $this->hasMany(ExternalPost::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SocialAccountSnapshot::class);
    }

    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class);
    }

    public function rssFeeds(): BelongsToMany
    {
        return $this->belongsToMany(RssFeed::class, 'rss_feed_social_account')
            ->withPivot('persona_id', 'auto_post', 'post_frequency', 'max_posts_per_day')
            ->withTimestamps();
    }
}
