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
        Schema::table('post_platform', function (Blueprint $table) {
            $table->json('metrics')->nullable()->after('error_message');
            $table->timestamp('metrics_synced_at')->nullable()->after('metrics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_platform', function (Blueprint $table) {
            $table->dropColumn(['metrics', 'metrics_synced_at']);
        });
    }
};
