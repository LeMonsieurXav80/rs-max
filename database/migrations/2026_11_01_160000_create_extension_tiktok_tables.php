<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_tiktok_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('username', 24)->unique();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('extension_tiktok_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('extension_tiktok_profiles')->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->char('persona_hash', 64);
            $table->string('author', 255);
            $table->text('content');
            $table->text('video_context')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('status', 20)->default('prepared');
            $table->boolean('liked')->default(false);
            $table->string('proof', 255)->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_tiktok_comments');
        Schema::dropIfExists('extension_tiktok_profiles');
    }
};
