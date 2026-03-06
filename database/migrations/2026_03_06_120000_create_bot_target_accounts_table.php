<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_target_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('handle'); // e.g. xainafairy.bsky.social
            $table->string('did')->nullable(); // resolved DID
            $table->enum('status', ['pending', 'running', 'paused', 'completed'])->default('pending');
            $table->string('current_post_uri')->nullable(); // which post's likers we're processing
            $table->string('current_cursor')->nullable(); // pagination cursor for getLikes
            $table->unsignedInteger('likers_processed')->default(0);
            $table->unsignedInteger('likes_given')->default(0);
            $table->unsignedInteger('follows_given')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_target_accounts');
    }
};
