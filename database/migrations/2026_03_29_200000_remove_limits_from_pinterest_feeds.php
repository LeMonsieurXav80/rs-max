<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pinterest_feeds', function (Blueprint $table) {
            $table->dropColumn(['max_items', 'items_per_day']);
        });
    }

    public function down(): void
    {
        Schema::table('pinterest_feeds', function (Blueprint $table) {
            $table->unsignedInteger('max_items')->default(50)->after('language');
            $table->unsignedInteger('items_per_day')->default(3)->after('max_items');
        });
    }
};
