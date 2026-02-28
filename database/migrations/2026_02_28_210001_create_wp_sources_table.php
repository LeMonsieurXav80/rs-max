<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('description')->nullable();
            $table->string('auth_username')->nullable();
            $table->text('auth_password')->nullable();
            $table->json('post_types')->nullable();
            $table->json('categories')->nullable();
            $table->string('schedule_frequency', 20)->default('weekly');
            $table->string('schedule_time', 5)->default('10:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_sources');
    }
};
