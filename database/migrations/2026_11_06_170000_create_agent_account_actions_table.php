<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_account_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('action', 60);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('daily_limit');
            $table->timestamps();

            $table->unique(['social_account_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_account_actions');
    }
};
