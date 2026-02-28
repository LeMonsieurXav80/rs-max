<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yt_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('channel_url');
            $table->string('channel_id', 50);
            $table->string('channel_name')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('schedule_frequency', 20)->default('weekly');
            $table->string('schedule_time', 5)->default('10:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yt_sources');
    }
};
