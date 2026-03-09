<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20)->index(); // comment, reply, dm
            $table->string('external_id')->index();
            $table->string('external_post_id')->nullable();
            $table->string('parent_id')->nullable();

            $table->string('author_name')->nullable();
            $table->string('author_username')->nullable();
            $table->string('author_avatar_url', 1024)->nullable();
            $table->string('author_external_id')->nullable();

            $table->text('content')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->string('media_type', 20)->nullable(); // image, gif, video, sticker
            $table->string('post_url', 1024)->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->string('status', 20)->default('unread')->index(); // unread, read, replied, archived, ignored
            $table->text('reply_content')->nullable();
            $table->string('reply_external_id')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('reply_scheduled_at')->nullable();
            $table->unsignedTinyInteger('reply_attempts')->default(0);

            $table->string('conversation_key')->nullable()->index();

            $table->timestamps();

            $table->unique(['platform_id', 'external_id'], 'inbox_platform_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
