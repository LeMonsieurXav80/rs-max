<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wp_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->text('generated_content')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('wp_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_posts');
    }
};
