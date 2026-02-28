<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_source_social_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wp_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('auto_post')->default(false);
            $table->string('post_frequency', 20)->default('daily');
            $table->unsignedSmallInteger('max_posts_per_day')->default(1);
            $table->timestamps();

            $table->unique(['wp_source_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_source_social_account');
    }
};
