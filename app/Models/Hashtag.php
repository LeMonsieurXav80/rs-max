<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hashtag extends Model
{
    protected $fillable = [
        'user_id',
        'tag',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'usage_count' => 'integer',
    ];

    /**
     * Hashtag appartient à un utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incrémenter le compteur d'utilisation
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Récupérer ou créer un hashtag pour un utilisateur
     */
    public static function recordUsage(int $userId, string $tag): void
    {
        // Nettoyer le tag (enlever # si présent, trim, lowercase)
        $cleanTag = strtolower(trim($tag, "# \t\n\r\0\x0B"));

        if (empty($cleanTag)) {
            return;
        }

        $hashtag = static::firstOrCreate(
            ['user_id' => $userId, 'tag' => $cleanTag],
            ['usage_count' => 0, 'last_used_at' => now()]
        );

        $hashtag->incrementUsage();
    }
}
