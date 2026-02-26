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
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('followers_count')->nullable()->after('profile_picture_url');
            $table->timestamp('followers_synced_at')->nullable()->after('followers_count');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'followers_synced_at']);
        });
    }
};
