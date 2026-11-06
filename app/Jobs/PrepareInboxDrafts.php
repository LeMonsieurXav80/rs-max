<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Inbox\InboxDraftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class PrepareInboxDrafts implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $accountId, public int $limit) {}

    public function handle(InboxDraftService $drafts): void
    {
        // Deux préparations du même compte produiraient deux fois la même dépense.
        Cache::lock("agent-prepare:{$this->accountId}", $this->timeout)->get(function () use ($drafts) {
            $drafts->prepare(SocialAccount::with(['platform', 'persona'])->findOrFail($this->accountId), $this->limit);
        });
    }
}
