<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserTask extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['social_account_id', 'profile', 'kind', 'payload', 'status', 'result', 'error', 'claimed_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
