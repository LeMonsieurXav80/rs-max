<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            // Chemin relatif (disque local) de la vignette légère pré-générée.
            // Null = pas encore générée (fallback à la volée dans thumbnail()).
            $table->string('thumbnail_path')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
