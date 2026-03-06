<?php

use App\Models\BotTargetAccount;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Health heartbeats
Schedule::call(fn () => Cache::put('health:scheduler', true, now()->addMinutes(5)))->everyMinute();
Schedule::call(fn () => dispatch(fn () => Cache::put('health:queue_worker', true, now()->addMinutes(5))))->everyMinute();

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

// Inbox sync - per-platform frequency configurable via Settings page
$inboxPlatforms = ['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit', 'twitter'];
foreach ($inboxPlatforms as $slug) {
    $enabled = rescue(fn () => (bool) Setting::get("inbox_platform_{$slug}_enabled", true), true, false);
    if (! $enabled) {
        continue;
    }

    $freq = rescue(fn () => Setting::get("inbox_sync_freq_{$slug}", 'every_15_min'), 'every_15_min', false);
    $platformSchedule = Schedule::command("inbox:sync --platform={$slug}")->withoutOverlapping(10);
    match ($freq) {
        'every_30_min' => $platformSchedule->everyThirtyMinutes(),
        'hourly' => $platformSchedule->hourly(),
        'every_2_hours' => $platformSchedule->everyTwoHours(),
        'every_6_hours' => $platformSchedule->cron('0 */6 * * *'),
        'every_12_hours' => $platformSchedule->twiceDaily(0, 12),
        'daily' => $platformSchedule->daily(),
        default => $platformSchedule->everyFifteenMinutes(),
    };
}

// Bot actions - runs every 15 min, each account has its own frequency setting
Schedule::command('bot:run')->everyFifteenMinutes()->withoutOverlapping(15);

// Recover orphaned prospect targets (stuck in 'running' after redeploy or crash)
Schedule::call(function () {
    $orphans = BotTargetAccount::where('status', 'running')
        ->where('updated_at', '<', now()->subMinutes(10))
        ->get();

    foreach ($orphans as $target) {
        // No active worker for this target — reset and requeue
        if (! Cache::has("bot_running_prospect_{$target->social_account_id}")) {
            $target->update(['status' => 'pending']);
            Artisan::queue('bot:prospect', ['--target' => $target->id]);
        }
    }
})->everyFiveMinutes()->name('prospect:recover-orphans')->withoutOverlapping(5);

// Check for application updates (hourly)
Schedule::call(function () {
    app(\App\Services\UpdateService::class)->checkForUpdate();
})->hourly()->name('update:check')->withoutOverlapping(10);

// Downsample follower snapshots (1st of each month at 3 AM)
Schedule::command('snapshots:downsample')->monthlyOn(1, '03:00');
