<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_platform', function (Blueprint $table) {
            $table->string('platform_url', 1024)->nullable()->after('external_id');
        });

        Schema::table('thread_segment_platform', function (Blueprint $table) {
            $table->string('platform_url', 1024)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_platform', function (Blueprint $table) {
            $table->dropColumn('platform_url');
        });

        Schema::table('thread_segment_platform', function (Blueprint $table) {
            $table->dropColumn('platform_url');
        });
    }
};
