<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reçu anti-doublon permanent, distinct du journal de sept jours et des tâches techniques. */
class MetaEngagement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array'];
    }
}
