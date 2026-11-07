<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('browser_task_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('target_key', 64);
            $table->string('status', 20)->default('prepared');
            $table->json('payload');
            $table->string('persona_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'action', 'target_key']);
        });
        Schema::create('social_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 100);
            $table->string('name')->nullable();
            $table->string('source', 40)->nullable();
            $table->text('source_url')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('followed_at')->nullable();
            $table->timestamp('unfollowed_at')->nullable();
            $table->string('follow_back', 10)->default('unknown');
            $table->timestamp('follow_back_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_contacts');
        Schema::dropIfExists('x_engagements');
    }
};
