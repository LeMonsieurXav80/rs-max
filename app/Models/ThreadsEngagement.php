<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reçu durable d'un geste Threads du script de fil : la tâche `threads.agent.act` qui l'exécute, et son issue. */
class ThreadsEngagement extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'prepared'];

    public const ACTIONS = ['like', 'comment'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'requested_at' => 'datetime'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function browserTask(): BelongsTo
    {
        return $this->belongsTo(BrowserTask::class);
    }
}
