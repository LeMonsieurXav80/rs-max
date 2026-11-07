<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_action_logs', function (Blueprint $table) {
            $table->foreignId('browser_task_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('account_action_event_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bot_action_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('browser_task_id');
            $table->dropConstrainedForeignId('account_action_event_id');
        });
    }
};
