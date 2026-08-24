<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
     * Position de chaque dossier dans l'ordre d'arbre (parcours en profondeur) :
     * un dossier est immédiatement suivi de ses descendants, les frères étant
     * triés par nom (insensible à la casse et aux accents, comme MySQL).
     *
     * Trier la liste plate par `path` alphabétique ne suffit PAS : « / » (47) est
     * supérieur à l'espace, au tiret, à la virgule ou à la parenthèse, donc un
     * dossier racine « PDC - Archives » se glisse entre « PDC » et
     * « PDC / Sous-dossier ». Déplier « PDC » n'affichait alors rien juste en
     * dessous — l'utilisateur croit que la flèche ne marche pas — et les enfants
     * indentés apparaissaient sous le mauvais parent.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $folders
     * @return array<int, int> [id du dossier => position]
     */
    public static function treeOrder($folders): array
    {
        $present = $folders->keyBy('id');
        $childrenByParent = $folders->groupBy(
            // Un dossier dont le parent est absent de la collection (filtrée) est traité comme racine.
            fn (self $f) => ($f->parent_id && $present->has($f->parent_id)) ? $f->parent_id : 0
        );
        $sortKey = fn (self $f) => Str::ascii(mb_strtolower((string) $f->name));

        $order = [];
        $position = 0;
        $walk = function ($parentId) use (&$walk, $childrenByParent, $sortKey, &$order, &$position) {
            foreach ($childrenByParent->get($parentId, collect())->sortBy($sortKey) as $folder) {
                if (isset($order[$folder->id])) {
                    continue; // garde-fou : cycle parent_id
                }
                $order[$folder->id] = $position++;
                $walk($folder->id);
            }
        };
        $walk(0);

        return $order;
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
     * Vrai si ce dossier OU l'un de ses ancêtres est privé.
     * Mirroir de la sémantique de descente publique de l'API (`collectPublicDescendantIds`) :
     * un ancêtre privé rend toute sa branche inaccessible côté API publique.
     */
    public function isEffectivelyPrivate(): bool
    {
        $cursor = $this;
        $depth = 0;
        while ($cursor && $depth < 10) {
            if ($cursor->is_private) {
                return true;
            }
            $cursor = $cursor->parent;
            $depth++;
        }

        return false;
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

    /**
     * Slug unique dérivé d'un nom (`media_folders.slug` porte un index unique).
     * `$ignoreId` permet de renommer un dossier sans qu'il entre en collision avec lui-même.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'dossier';
        $slug = $base;
        $counter = 1;

        while (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
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
