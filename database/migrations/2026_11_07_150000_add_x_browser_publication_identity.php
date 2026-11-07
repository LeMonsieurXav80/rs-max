<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_tasks', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable();
            $table->unique(['social_account_id', 'idempotency_key']);
        });
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->json('browser_followers_observation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn('browser_followers_observation'));
        Schema::table('browser_tasks', function (Blueprint $table) {
            $table->dropUnique(['social_account_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
