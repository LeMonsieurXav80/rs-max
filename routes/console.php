<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Publish scheduled posts every minute
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping(5);

// Sync follower counts once daily at 6 AM (pay-per-use API cost optimization)
Schedule::command('followers:sync')->dailyAt('06:00')->withoutOverlapping(30);

// Stats sync - frequency configurable via Settings page
$syncFreq = rescue(fn () => Setting::get('stats_sync_frequency', 'hourly'), 'hourly', false);
$statsSchedule = Schedule::command('stats:sync')->withoutOverlapping(15);
match ($syncFreq) {
    'every_15_min' => $statsSchedule->everyFifteenMinutes(),
    'every_30_min' => $statsSchedule->everyThirtyMinutes(),
    'every_2_hours' => $statsSchedule->everyTwoHours(),
    'every_6_hours' => $statsSchedule->cron('0 */6 * * *'),
    'every_12_hours' => $statsSchedule->twiceDaily(0, 12),
    'daily' => $statsSchedule->daily(),
    default => $statsSchedule->hourly(),
};

// Send scheduled inbox replies every minute
Schedule::command('inbox:send-scheduled')->everyMinute()->withoutOverlapping(5);

// Inbox sync - frequency configurable via Settings page
$inboxFreq = rescue(fn () => Setting::get('inbox_sync_frequency', 'every_15_min'), 'every_15_min', false);
$inboxSchedule = Schedule::command('inbox:sync')->withoutOverlapping(10);
match ($inboxFreq) {
    'every_30_min' => $inboxSchedule->everyThirtyMinutes(),
    'hourly' => $inboxSchedule->hourly(),
    'every_2_hours' => $inboxSchedule->everyTwoHours(),
    'every_6_hours' => $inboxSchedule->cron('0 */6 * * *'),
    'every_12_hours' => $inboxSchedule->twiceDaily(0, 12),
    'daily' => $inboxSchedule->daily(),
    default => $inboxSchedule->everyFifteenMinutes(),
};

// Bot actions - runs every 15 min, each account has its own frequency setting
Schedule::command('bot:run')->everyFifteenMinutes()->withoutOverlapping(15);

// Downsample follower snapshots (1st of each month at 3 AM)
Schedule::command('snapshots:downsample')->monthlyOn(1, '03:00');
