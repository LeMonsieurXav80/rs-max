<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('browser_task_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('target_key', 64);
            $table->string('request_key', 36);
            $table->string('status', 20)->default('pending');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'action', 'target_key']);
            $table->unique(['social_account_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_engagements');
    }
};
