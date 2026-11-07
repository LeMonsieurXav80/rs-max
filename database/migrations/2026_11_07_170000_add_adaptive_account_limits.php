<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unsignedInteger('agent_daily_limit')->nullable();
            $table->json('agent_adaptation')->nullable();
            $table->json('agent_adaptation_state')->nullable();
        });
        Schema::create('agent_limit_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('old_hourly')->nullable();
            $table->unsignedInteger('new_hourly')->nullable();
            $table->unsignedInteger('old_daily')->nullable();
            $table->unsignedInteger('new_daily')->nullable();
            $table->unsignedInteger('old_interval');
            $table->unsignedInteger('new_interval');
            $table->string('reason', 500);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['social_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_limit_adjustments');
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn(['agent_daily_limit', 'agent_adaptation', 'agent_adaptation_state']));
    }
};
