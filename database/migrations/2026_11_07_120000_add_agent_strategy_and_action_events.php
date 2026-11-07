<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->json('agent_strategy')->nullable();
            $table->unsignedInteger('agent_hourly_limit')->nullable();
            $table->unsignedInteger('agent_min_interval_seconds')->default(0);
        });
        Schema::table('inbox_items', fn (Blueprint $t) => $t->json('reply_activity_window')->nullable());
        Schema::create('account_action_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('browser_task_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('executor', 20);
            $table->string('target', 255)->nullable();
            $table->string('status', 20)->default('attempted');
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->index(['social_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_action_events');
        Schema::table('inbox_items', fn (Blueprint $t) => $t->dropColumn('reply_activity_window'));
        Schema::table('social_accounts', fn (Blueprint $t) => $t->dropColumn(['agent_strategy', 'agent_hourly_limit', 'agent_min_interval_seconds']));
    }
};
