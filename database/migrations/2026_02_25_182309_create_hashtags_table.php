<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('tag')->index(); // Le hashtag sans le #
            $table->unsignedInteger('usage_count')->default(1); // Nombre d'utilisations
            $table->timestamp('last_used_at')->useCurrent();
            $table->timestamps();

            // Un hashtag unique par utilisateur
            $table->unique(['user_id', 'tag']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hashtags');
    }
};
