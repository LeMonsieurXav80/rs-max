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

    protected $fillable = [
        'name',
        'email',
        'password',
        'default_language',
        'auto_translate',
        'openai_api_key',
        'telegram_alert_chat_id',
        'is_admin',
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
            'is_admin' => 'boolean',
        ];
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class)->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function activeSocialAccounts(): BelongsToMany
    {
        return $this->socialAccounts()->where('is_active', true);
    }
}
