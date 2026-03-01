<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'folder_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
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
