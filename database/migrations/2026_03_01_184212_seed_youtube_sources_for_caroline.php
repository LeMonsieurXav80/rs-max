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

        // Find Caroline's user ID.
        $caroline = DB::table('users')->where('email', 'caroline@laplanetedecaro.com')->first();
        if (! $caroline) {
            return;
        }

        // Find YouTube social accounts matching these channel IDs.
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
    }

    public function down(): void
    {
        $caroline = DB::table('users')->where('email', 'caroline@laplanetedecaro.com')->first();
        if (! $caroline) {
            return;
        }

        $channelIds = [
            'UCBKcSwUe-59z62BvE8VIGJg',
            'UCxHshl5wN0ljpphZmv0pYNw',
        ];

        $accountIds = DB::table('social_accounts')
            ->whereIn('platform_account_id', $channelIds)
            ->pluck('id');

        DB::table('social_account_user')
            ->where('user_id', $caroline->id)
            ->whereIn('social_account_id', $accountIds)
            ->delete();
    }
};
