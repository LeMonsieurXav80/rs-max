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
        Schema::table('external_posts', function (Blueprint $table) {
            $table->text('media_url')->nullable()->change();
            $table->text('post_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->string('media_url')->nullable()->change();
            $table->string('post_url')->nullable()->change();
        });
    }
};
