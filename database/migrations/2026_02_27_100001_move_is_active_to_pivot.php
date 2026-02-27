<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add is_active to the pivot table (per-user activation)
        Schema::table('social_account_user', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('user_id');
        });

        // 2. Copy current global is_active to all pivot entries
        $accounts = DB::table('social_accounts')->select('id', 'is_active')->get();
        foreach ($accounts as $account) {
            DB::table('social_account_user')
                ->where('social_account_id', $account->id)
                ->update(['is_active' => $account->is_active]);
        }

        // 3. Remove is_active from social_accounts
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        // Re-add is_active to social_accounts
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('show_branding');
        });

        // Copy pivot is_active back (use first user's value)
        $pivots = DB::table('social_account_user')
            ->select('social_account_id', DB::raw('MAX(is_active) as is_active'))
            ->groupBy('social_account_id')
            ->get();

        foreach ($pivots as $pivot) {
            DB::table('social_accounts')
                ->where('id', $pivot->social_account_id)
                ->update(['is_active' => $pivot->is_active]);
        }

        // Drop is_active from pivot
        Schema::table('social_account_user', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
