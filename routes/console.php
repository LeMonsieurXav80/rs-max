<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Publish scheduled posts every minute
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();

// RSS auto-generate posts every 6 hours
Schedule::command('rss:generate')->cron('0 */6 * * *')->withoutOverlapping();

// Stats sync - frequency configurable via Settings page
$syncFreq = rescue(fn () => Setting::get('stats_sync_frequency', 'hourly'), 'hourly', false);
$statsSchedule = Schedule::command('stats:sync')->withoutOverlapping();
match ($syncFreq) {
    'every_15_min' => $statsSchedule->everyFifteenMinutes(),
    'every_30_min' => $statsSchedule->everyThirtyMinutes(),
    'every_2_hours' => $statsSchedule->everyTwoHours(),
    'every_6_hours' => $statsSchedule->cron('0 */6 * * *'),
    'every_12_hours' => $statsSchedule->twiceDaily(0, 12),
    'daily' => $statsSchedule->daily(),
    default => $statsSchedule->hourly(),
};
