<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'color',
        'is_system',
        'is_private',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_private' => 'boolean',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'folder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }

    /**
     * Retourne les ids du dossier + tous ses descendants (récursif).
     * Utilisé pour filtrer la médiathèque sur un dossier ET ses sous-dossiers.
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $stack = [$this->id];

        while ($stack) {
            $children = self::whereIn('parent_id', $stack)->pluck('id')->all();
            if (! $children) {
                break;
            }
            $ids = array_merge($ids, $children);
            $stack = $children;
        }

        return $ids;
    }

    /**
     * Construit le chemin lisible du dossier (`Parent / Sous / Petit-fils`).
     */
    public function pathLabel(string $separator = ' / '): string
    {
        $names = [$this->name];
        $cursor = $this->parent;
        $depth = 0;
        while ($cursor && $depth < 10) {
            array_unshift($names, $cursor->name);
            $cursor = $cursor->parent;
            $depth++;
        }

        return implode($separator, $names);
    }

    public static function ensureDefaultFolder(): self
    {
        return self::firstOrCreate(
            ['slug' => 'flux-pictures'],
            [
                'name' => 'Flux Pictures',
                'color' => '#f59e0b',
                'is_system' => true,
                'sort_order' => 0,
            ]
        );
    }
}
