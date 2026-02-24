<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add new columns to social_accounts
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('platform_account_id')->nullable()->after('platform_id');
            $table->string('profile_picture_url')->nullable()->after('name');
        });

        // 2. Create pivot table
        Schema::create('social_account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['social_account_id', 'user_id']);
        });

        // 3. Data migration: extract platform_account_id and create pivot entries
        $this->migrateData();

        // 4. Drop user_id from social_accounts and add unique index
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex('social_accounts_user_id_platform_id_index');
            $table->dropColumn('user_id');

            $table->unique(['platform_id', 'platform_account_id']);
        });
    }

    private function migrateData(): void
    {
        // Use the model to decrypt credentials
        $accounts = \App\Models\SocialAccount::all();

        // Extract platform_account_id from credentials
        $platformIdMapping = [
            'facebook' => 'page_id',
            'instagram' => 'account_id',
            'telegram' => 'chat_id',
        ];

        $platformSlugs = DB::table('platforms')->pluck('slug', 'id')->toArray();

        foreach ($accounts as $account) {
            $slug = $platformSlugs[$account->platform_id] ?? '';
            $credKey = $platformIdMapping[$slug] ?? null;
            $platformAccountId = null;

            if ($credKey && isset($account->credentials[$credKey])) {
                $platformAccountId = $account->credentials[$credKey];
            }

            if ($platformAccountId) {
                DB::table('social_accounts')
                    ->where('id', $account->id)
                    ->update(['platform_account_id' => $platformAccountId]);
            }
        }

        // Reload with platform_account_id set
        $accounts = \App\Models\SocialAccount::all();

        // Group by platform_id + platform_account_id to find duplicates
        $groups = $accounts->groupBy(function ($a) {
            return $a->platform_id . ':' . ($a->platform_account_id ?? 'null_' . $a->id);
        });

        foreach ($groups as $key => $group) {
            if ($group->count() <= 1) {
                // No duplicate — just create pivot entry
                $account = $group->first();
                DB::table('social_account_user')->insertOrIgnore([
                    'social_account_id' => $account->id,
                    'user_id' => $account->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                continue;
            }

            // Duplicate: keep the newest one (most recent OAuth token), merge users
            $keeper = $group->sortByDesc('updated_at')->first();

            // Collect all user_ids and create pivot entries
            foreach ($group as $account) {
                DB::table('social_account_user')->insertOrIgnore([
                    'social_account_id' => $keeper->id,
                    'user_id' => $account->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update the keeper with the best name (prefer non-"main")
            $bestName = $group->first(fn ($a) => $a->name !== 'main')?->name ?? $keeper->name;
            if ($bestName !== $keeper->name) {
                DB::table('social_accounts')
                    ->where('id', $keeper->id)
                    ->update(['name' => $bestName]);
            }

            // Move post_platform references from duplicates to keeper
            $duplicateIds = $group->where('id', '!=', $keeper->id)->pluck('id')->toArray();
            if (! empty($duplicateIds)) {
                DB::table('post_platform')
                    ->whereIn('social_account_id', $duplicateIds)
                    ->update(['social_account_id' => $keeper->id]);

                // Delete duplicate accounts
                DB::table('social_accounts')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Re-add user_id (non-reversible data migration)
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['platform_id', 'platform_account_id']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['user_id', 'platform_id']);
        });

        // Copy first linked user back to user_id
        $pivots = DB::table('social_account_user')
            ->select('social_account_id', DB::raw('MIN(user_id) as user_id'))
            ->groupBy('social_account_id')
            ->get();

        foreach ($pivots as $pivot) {
            DB::table('social_accounts')
                ->where('id', $pivot->social_account_id)
                ->update(['user_id' => $pivot->user_id]);
        }

        Schema::dropIfExists('social_account_user');

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['platform_account_id', 'profile_picture_url']);
        });
    }
};
