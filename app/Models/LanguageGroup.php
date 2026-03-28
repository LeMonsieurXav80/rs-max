<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LanguageGroup extends Model
{
    protected $fillable = [
        'name',
        'languages',
    ];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
        ];
    }
}
