<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->text('bot_comment_context_article')->nullable()->after('is_active');
            $table->text('bot_comment_context_text')->nullable()->after('bot_comment_context_article');
            $table->text('bot_comment_context_image')->nullable()->after('bot_comment_context_text');
            $table->unsignedSmallInteger('bot_comment_max_length')->default(280)->after('bot_comment_context_image');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn([
                'bot_comment_context_article',
                'bot_comment_context_text',
                'bot_comment_context_image',
                'bot_comment_max_length',
            ]);
        });
    }
};
