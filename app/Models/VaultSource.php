<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VaultSource extends Model
{
    public const FORMATS = ['notes' => 'Notes (titre, résumé, texte)', 'pubmed' => 'Fiches PubMed (Recherches)'];

    /** Jamais servis, même cochés par erreur : le service de recherche applique la même liste. */
    public const FORBIDDEN = ['07-Raw', 'Compta', 'Mails', 'Contacts', 'Contacts-Pro', 'Partenaires', 'Credentials', 'CREDENTIALS', '.git', '.obsidian', '.trash'];

    protected $fillable = ['name', 'corpus', 'format', 'folders', 'excluded_folders', 'brands', 'is_active'];

    protected function casts(): array
    {
        return ['folders' => 'array', 'excluded_folders' => 'array', 'brands' => 'array', 'is_active' => 'boolean'];
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class);
    }

    public static function forbidden(string $folder): bool
    {
        $parts = explode('/', trim($folder, '/'));

        return collect($parts)->contains(fn ($part) => in_array($part, self::FORBIDDEN, true) || $part === '..' || $part === '')
            || preg_match('/credential|password|secret|token/i', $folder);
    }
}
