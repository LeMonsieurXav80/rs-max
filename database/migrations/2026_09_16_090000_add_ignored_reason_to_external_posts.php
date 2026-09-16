<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue « ignoree par un humain » de « ecartee automatiquement ».
 *
 * L'adoption automatique doit pouvoir mettre de cote ce qui n'est pas une vraie
 * publication (partage de Page, reponse dans un fil) sans que ca se confonde
 * avec un choix de l'utilisateur : sinon impossible de revenir sur une regle
 * trop large sans ecraser ses decisions a lui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->string('ignored_reason', 32)->nullable()->after('ignored_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_posts', function (Blueprint $table) {
            $table->dropColumn('ignored_reason');
        });
    }
};
