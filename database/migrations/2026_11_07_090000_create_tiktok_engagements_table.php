<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('target_key', 64);
            $table->json('payload');
            $table->string('persona_hash', 64)->nullable();
            $table->string('status', 20)->default('prepared');
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'action', 'target_key'], 'tiktok_engagement_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_engagements');
    }
};
