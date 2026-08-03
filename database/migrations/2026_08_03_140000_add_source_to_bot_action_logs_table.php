<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ouvre bot_action_logs aux actions venues de l'extension Chrome.
 *
 * Le bot serveur et l'extension navigateur produisent le même genre de trace
 * (une action, une cible, un succès) : on garde une seule table plutôt que d'en
 * créer une concurrente, et `source` distingue qui a agi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_action_logs', function (Blueprint $table) {
            $table->string('source')->default('bot')->after('action_type');
            $table->json('metadata')->nullable()->after('error');
            // Horodatage réel de l'action côté navigateur : la remontée peut
            // être différée (file d'attente hors ligne), created_at ne suffit pas.
            $table->timestamp('performed_at')->nullable()->after('metadata');

            $table->index(['source', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_action_logs', function (Blueprint $table) {
            $table->dropIndex(['source', 'action_type']);
            $table->dropColumn(['source', 'metadata', 'performed_at']);
        });
    }
};
