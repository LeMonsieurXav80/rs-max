<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un template s'écrit en deux morceaux : le gabarit (HTML) et sa feuille de
 * style. Séparer le CSS évite d'avoir à tout mettre en styles inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carousel_bricks', function (Blueprint $table) {
            $table->longText('css')->nullable()->after('html');
        });
    }

    public function down(): void
    {
        Schema::table('carousel_bricks', function (Blueprint $table) {
            $table->dropColumn('css');
        });
    }
};
