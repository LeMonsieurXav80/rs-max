<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('is_system');
            $table->index('is_private');
        });

        // Flux Pictures = bac à photos brutes non triées → privé par défaut.
        DB::table('media_folders')
            ->where('slug', 'flux-pictures')
            ->update(['is_private' => true]);
    }

    public function down(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->dropIndex(['is_private']);
            $table->dropColumn('is_private');
        });
    }
};
