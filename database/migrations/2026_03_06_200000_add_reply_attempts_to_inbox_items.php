<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inbox_items', 'reply_attempts')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('reply_attempts')->default(0)->after('reply_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn('reply_attempts');
        });
    }
};
