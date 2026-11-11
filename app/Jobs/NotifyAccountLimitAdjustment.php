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
        if (($row?->context['source'] ?? null) !== 'platform_ui') {
            return;
        }

        $account = SocialAccount::with('platform')->find($row->social_account_id);
        if (! $account) {
            return;
        }
        $changed = $row->old_hourly !== $row->new_hourly || $row->old_daily !== $row->new_daily;
        $title = $changed ? 'Plafonds ajustés' : 'Plafonds inchangés';
        $message = "RS-Max · {$account->name} · {$account->platform->name}\n{$title} : {$row->new_hourly}/h, {$row->new_daily}/jour.\n{$row->reason}";
        $context = $row->context ?? [];
        if (! empty($context['cooldown_until'])) {
            $until = \Illuminate\Support\Carbon::parse($context['cooldown_until'])->tz('Europe/Lisbon')->format('H:i');
            $message .= "\nPause jusqu’à {$until} (Lisbonne).";
        }
        TelegramNotificationService::sendConfiguredText($message);
    }
}
