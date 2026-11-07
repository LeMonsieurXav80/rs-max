<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TikTokEngagement extends Model
{
    protected $table = 'tiktok_engagements';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'prepared'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public static function personaHash(SocialAccount $account): string
    {
        return hash('sha256', $account->id.':'.$account->persona?->id.':'.$account->persona?->system_prompt);
    }
}
