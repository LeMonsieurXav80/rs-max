<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    const ROLE_USER = 'user';
    const ROLE_MANAGER = 'manager';
    const ROLE_ADMIN = 'admin';

    const ROLE_LEVELS = [
        'user' => 0,
        'manager' => 1,
        'admin' => 2,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'default_language',
        'auto_translate',
        'openai_api_key',
        'telegram_alert_chat_id',
        'role',
        'default_accounts',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'openai_api_key',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'openai_api_key' => 'encrypted',
            'auto_translate' => 'boolean',
            'default_accounts' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isManager(): bool
    {
        return in_array($this->role, [self::ROLE_MANAGER, self::ROLE_ADMIN]);
    }

    public function isAtLeast(string $role): bool
    {
        return (self::ROLE_LEVELS[$this->role] ?? 0) >= (self::ROLE_LEVELS[$role] ?? 0);
    }

    // Backward compat accessor for blade templates during transition
    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class)->withPivot('is_active')->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function activeSocialAccounts(): BelongsToMany
    {
        return $this->socialAccounts()->wherePivot('is_active', true);
    }
}
