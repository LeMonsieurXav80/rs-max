<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_search_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('term');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_likes_per_run')->default(10);
            $table->boolean('like_replies')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'term']);
        });

        Schema::create('bot_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('action_type'); // like_post, like_reply, like_comment
            $table->string('target_uri');  // AT URI or Facebook comment ID
            $table->string('target_author')->nullable();
            $table->text('target_text')->nullable();
            $table->string('search_term')->nullable();
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['social_account_id', 'action_type']);
            $table->index('target_uri');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_action_logs');
        Schema::dropIfExists('bot_search_terms');
    }
};
