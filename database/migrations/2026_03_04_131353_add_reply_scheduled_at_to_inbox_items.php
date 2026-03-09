<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbox_items')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->timestamp('reply_scheduled_at')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn('reply_scheduled_at');
        });
    }
};
