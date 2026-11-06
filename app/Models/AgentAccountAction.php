<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAccountAction extends Model
{
    protected $fillable = ['social_account_id', 'action', 'enabled', 'daily_limit'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'daily_limit' => 'integer'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
