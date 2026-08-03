<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Marque / partenaire tague sur une photo et sur une publication.
 * Le slug sert de cle de dedup insensible a la casse et aux accents.
 */
class Partner extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'contact_name',
        'contact_email',
        'website',
        'notes',
        'color',
        'is_active',
        'origin',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Partner $partner) {
            if (empty($partner->slug) || $partner->isDirty('name')) {
                $partner->slug = static::slugFor($partner->name);
            }
        });
    }

    public static function slugFor(string $name): string
    {
        return Str::slug(trim($name));
    }

    public function mediaFiles(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class, 'media_file_partner')->withTimestamps();
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'partner_post')
            ->withPivot('source')
            ->withTimestamps();
    }

    public function threads(): BelongsToMany
    {
        return $this->belongsToMany(Thread::class, 'partner_thread')
            ->withPivot('source')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
