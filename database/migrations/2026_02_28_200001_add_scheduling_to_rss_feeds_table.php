<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rss_feeds', function (Blueprint $table) {
            $table->string('schedule_frequency', 20)->default('weekly')->after('is_active');
            $table->string('schedule_time', 5)->default('10:00')->after('schedule_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('rss_feeds', function (Blueprint $table) {
            $table->dropColumn(['schedule_frequency', 'schedule_time']);
        });
    }
};
