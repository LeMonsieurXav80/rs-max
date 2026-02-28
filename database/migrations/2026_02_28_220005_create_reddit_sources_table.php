<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reddit_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subreddit', 100);
            $table->string('sort_by', 20)->default('hot');
            $table->string('time_filter', 20)->default('week');
            $table->integer('min_score')->default(0);
            $table->string('schedule_frequency', 20)->default('weekly');
            $table->string('schedule_time', 5)->default('10:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reddit_sources');
    }
};
