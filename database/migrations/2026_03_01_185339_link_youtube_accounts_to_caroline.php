<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $channelIds = [
            'UCBKcSwUe-59z62BvE8VIGJg',
            'UCxHshl5wN0ljpphZmv0pYNw',
        ];

        $caroline = DB::table('users')->where('email', 'caroline@laplanetedecaro.com')->first();
        if (! $caroline) {
            return;
        }

        $accounts = DB::table('social_accounts')
            ->whereIn('platform_account_id', $channelIds)
            ->pluck('id');

        foreach ($accounts as $accountId) {
            DB::table('social_account_user')->insertOrIgnore([
                'social_account_id' => $accountId,
                'user_id' => $caroline->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clean up yt_sources created by previous migration (not needed).
        DB::table('yt_sources')->whereIn('channel_id', $channelIds)->delete();
    }

    public function down(): void
    {
        $caroline = DB::table('users')->where('email', 'caroline@laplanetedecaro.com')->first();
        if (! $caroline) {
            return;
        }

        $accountIds = DB::table('social_accounts')
            ->whereIn('platform_account_id', [
                'UCBKcSwUe-59z62BvE8VIGJg',
                'UCxHshl5wN0ljpphZmv0pYNw',
            ])
            ->pluck('id');

        DB::table('social_account_user')
            ->where('user_id', $caroline->id)
            ->whereIn('social_account_id', $accountIds)
            ->delete();
    }
};
