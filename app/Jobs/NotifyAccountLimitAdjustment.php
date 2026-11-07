<?php

namespace App\Jobs;

use App\Models\AgentLimitAdjustment;
use App\Models\SocialAccount;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyAccountLimitAdjustment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $adjustmentId) {}

    public function handle(): void
    {
        $row = AgentLimitAdjustment::find($this->adjustmentId);
        $account = $row ? SocialAccount::with('platform')->find($row->social_account_id) : null;
        if (! $account) {
            return;
        }
        TelegramNotificationService::sendConfiguredText("RS-Max · {$account->name} · {$account->platform->name}\nCadence : {$row->new_hourly}/h, {$row->new_daily}/jour.\n{$row->reason}");
    }
}
