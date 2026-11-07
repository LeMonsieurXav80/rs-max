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
        'browser_session',
    ];

    protected $fillable = [
        'platform_id',
        'platform_account_id',
        'driver',
        'browser_profile',
        'browser_session',
        'browser_username',
        'browser_followers_observation',
        'browser_session_status',
        'browser_session_received_at',
        'browser_session_checked_at',
        'subscription_type',
        'name',
        'profile_picture_url',
        'followers_count',
        'followers_synced_at',
        'credentials',
        'languages',
        'persona_id',
        'agent_strategy',
        'agent_hourly_limit',
        'agent_daily_limit',
        'agent_adaptation',
        'agent_adaptation_state',
        'agent_min_interval_seconds',
        'last_used_at',
        'last_history_import_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'browser_session' => 'encrypted:array',
            'browser_followers_observation' => 'array',
            'browser_session_received_at' => 'datetime',
            'browser_session_checked_at' => 'datetime',
            'languages' => 'array',
            'agent_strategy' => 'array',
            'agent_hourly_limit' => 'integer',
            'agent_daily_limit' => 'integer',
            'agent_adaptation' => 'array',
            'agent_adaptation_state' => 'array',
            'agent_min_interval_seconds' => 'integer',
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

    /** Vrai quand le compte passe par le navigateur du VPS, pas par l'API du réseau. */
    public function usesBrowser(): bool
    {
        return $this->driver === 'browser';
    }

    public function agentActions(): HasMany
    {
        return $this->hasMany(AgentAccountAction::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SocialAccountSnapshot::class);
    }

    public function inboxItems(): HasMany
    {
        return $this->hasMany(InboxItem::class);
    }
}
