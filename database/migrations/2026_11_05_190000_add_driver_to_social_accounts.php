<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('driver', 20)->default('api')->after('platform_account_id');
            $table->text('browser_session')->nullable();
            $table->string('browser_username', 15)->nullable();
            $table->string('browser_session_status', 20)->nullable();
            $table->timestamp('browser_session_received_at')->nullable();
            $table->timestamp('browser_session_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['driver', 'browser_session', 'browser_username', 'browser_session_status',
                'browser_session_received_at', 'browser_session_checked_at']);
        });
    }
};
