<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pinterest_feeds', function (Blueprint $table) {
            $table->string('interest', 50)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('pinterest_feeds', function (Blueprint $table) {
            $table->dropColumn('interest');
        });
    }
};
