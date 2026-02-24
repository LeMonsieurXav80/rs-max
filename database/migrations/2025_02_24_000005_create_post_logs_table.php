<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_platform_id')->constrained('post_platform')->cascadeOnDelete();
            $table->string('action'); // submitted, published, failed, retried
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('post_platform_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_logs');
    }
};
