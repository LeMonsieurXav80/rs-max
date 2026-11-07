<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentLimitAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
