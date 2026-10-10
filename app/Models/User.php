<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Voir l'ensemble des contenus, quel que soit leur auteur ou le compte sur
     * lequel ils sont partis : publications, fils, boite de reception, stats.
     *
     * Volontairement distinct de `isAdmin()`, qui garde les droits d'ECRITURE
     * sur le contenu d'autrui (modifier, taguer, supprimer). Un manager voit
     * tout et peut relancer un reseau tombe en erreur, rien de plus — a deux
     * sur l'outil, l'angle mort coutait plus cher que le cloisonnement.
     */
    public function seesAllContent(): bool
    {
        return $this->isAtLeast(self::ROLE_MANAGER);
    }

    /**
     * Les comptes sociaux sur lesquels l'utilisateur a droit de REGARD.
     *
     * A ne pas confondre avec `activeSocialAccounts()`, qui reste la liste des
     * comptes vers lesquels il peut PUBLIER : ouvrir la seconde donnerait a
     * Caroline le droit de poster sur des comptes qui ne sont pas les siens,
     * ce qui n'est pas demande.
     *
     * Renvoie un Builder et non une relation : pour qui voit tout, il n'y a
     * aucun pivot a traverser. Le pivot est donc exprime en `whereHas` plutot
     * que par `activeSocialAccounts()->getQuery()` — derouler une relation
     * many-to-many en Builder laisse derriere elle ses colonnes de pivot et
     * son `select` implicite, qui se comportent mal des qu'on enchaine un
     * `pluck()` ou un `with()`.
     */
    public function visibleSocialAccounts(): Builder
    {
        if ($this->seesAllContent()) {
            return SocialAccount::query();
        }

        return SocialAccount::whereHas('users', fn ($q) => $q
            ->where('users.id', $this->id)
            ->where('social_account_user.is_active', true));
    }

    public function accountGroups(): HasMany
    {
        return $this->hasMany(AccountGroup::class)->orderBy('sort_order');
    }
}
