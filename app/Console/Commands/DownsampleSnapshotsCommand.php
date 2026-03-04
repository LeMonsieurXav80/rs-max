<?php

namespace App\Console\Commands;

use App\Models\SocialAccountSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DownsampleSnapshotsCommand extends Command
{
    protected $signature = 'snapshots:downsample';

    protected $description = 'Downsample old follower snapshots (daily→weekly after 12 months, weekly→monthly after 24 months)';

    public function handle(): int
    {
        $this->info('Starting snapshot downsampling...');

        $weeklyCount = $this->downsampleDailyToWeekly();
        $monthlyCount = $this->downsampleWeeklyToMonthly();

        $this->info("Downsampled: {$weeklyCount} daily→weekly, {$monthlyCount} weekly→monthly");

        return self::SUCCESS;
    }

    /**
     * Daily snapshots older than 12 months → keep last value per week (Sunday), delete others.
     */
    private function downsampleDailyToWeekly(): int
    {
        $cutoff = Carbon::now()->subMonths(12)->toDateString();

        // Get all daily snapshots older than 12 months, grouped by account and week
        $oldDailySnapshots = SocialAccountSnapshot::where('granularity', 'daily')
            ->where('date', '<', $cutoff)
            ->orderBy('social_account_id')
            ->orderBy('date')
            ->get();

        if ($oldDailySnapshots->isEmpty()) {
            $this->info('No daily snapshots to downsample.');

            return 0;
        }

        $deletedCount = 0;

        // Group by account, then by ISO week
        $grouped = $oldDailySnapshots->groupBy(function ($snapshot) {
            $weekEnd = Carbon::parse($snapshot->date)->endOfWeek(Carbon::SUNDAY);

            return $snapshot->social_account_id . '_' . $weekEnd->toDateString();
        });

        DB::transaction(function () use ($grouped, &$deletedCount) {
            foreach ($grouped as $weekSnapshots) {
                // Keep the last snapshot of the week (closest to Sunday)
                $keeper = $weekSnapshots->sortByDesc('date')->first();

                // Create a weekly record if it doesn't exist
                SocialAccountSnapshot::updateOrCreate(
                    [
                        'social_account_id' => $keeper->social_account_id,
                        'date' => Carbon::parse($keeper->date)->endOfWeek(Carbon::SUNDAY)->toDateString(),
                        'granularity' => 'weekly',
                    ],
                    [
                        'followers_count' => $keeper->followers_count,
                        'created_at' => $keeper->created_at,
                    ]
                );

                // Delete all daily snapshots for this week
                $idsToDelete = $weekSnapshots->pluck('id')->toArray();
                SocialAccountSnapshot::whereIn('id', $idsToDelete)->delete();
                $deletedCount += count($idsToDelete);
            }
        });

        return $deletedCount;
    }

    /**
     * Weekly snapshots older than 24 months → keep last value per month, delete others.
     */
    private function downsampleWeeklyToMonthly(): int
    {
        $cutoff = Carbon::now()->subMonths(24)->toDateString();

        $oldWeeklySnapshots = SocialAccountSnapshot::where('granularity', 'weekly')
            ->where('date', '<', $cutoff)
            ->orderBy('social_account_id')
            ->orderBy('date')
            ->get();

        if ($oldWeeklySnapshots->isEmpty()) {
            $this->info('No weekly snapshots to downsample.');

            return 0;
        }

        $deletedCount = 0;

        // Group by account and month
        $grouped = $oldWeeklySnapshots->groupBy(function ($snapshot) {
            $monthEnd = Carbon::parse($snapshot->date)->endOfMonth();

            return $snapshot->social_account_id . '_' . $monthEnd->format('Y-m');
        });

        DB::transaction(function () use ($grouped, &$deletedCount) {
            foreach ($grouped as $monthSnapshots) {
                $keeper = $monthSnapshots->sortByDesc('date')->first();

                SocialAccountSnapshot::updateOrCreate(
                    [
                        'social_account_id' => $keeper->social_account_id,
                        'date' => Carbon::parse($keeper->date)->endOfMonth()->toDateString(),
                        'granularity' => 'monthly',
                    ],
                    [
                        'followers_count' => $keeper->followers_count,
                        'created_at' => $keeper->created_at,
                    ]
                );

                $idsToDelete = $monthSnapshots->pluck('id')->toArray();
                SocialAccountSnapshot::whereIn('id', $idsToDelete)->delete();
                $deletedCount += count($idsToDelete);
            }
        });

        return $deletedCount;
    }
}
