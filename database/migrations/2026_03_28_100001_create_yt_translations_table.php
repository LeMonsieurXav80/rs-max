<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yt_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('video_id', 50);
            $table->string('language', 10);
            $table->string('type', 20); // 'title', 'description', 'subtitles'
            $table->text('original_text')->nullable();
            $table->text('translated_text')->nullable();
            $table->string('status', 20)->default('pending'); // pending, translating, translated, uploaded, failed
            $table->timestamp('uploaded_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'video_id', 'language', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yt_translations');
    }
};
