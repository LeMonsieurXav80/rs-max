<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $fillable = [
        'name',
        'description',
        'system_prompt',
        'tone',
        'language',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rssPosts(): HasMany
    {
        return $this->hasMany(RssPost::class);
    }
}
