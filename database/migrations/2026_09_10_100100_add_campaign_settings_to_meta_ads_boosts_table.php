<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ce qui a REELLEMENT ete demande a Meta, campagne par campagne.
     *
     * Jusqu'ici la table ne gardait que le budget et la duree : l'objectif et le
     * ciblage etaient en dur dans le code, donc inutiles a stocker. Maintenant
     * qu'ils se choisissent, ne pas les conserver reviendrait a ne plus pouvoir
     * dire pourquoi une campagne a sous-performe — l'audience aura pu changer
     * entre-temps, d'ou la copie figee dans `targeting` et pas seulement le lien
     * vers `meta_audiences`.
     */
    public function up(): void
    {
        Schema::table('meta_ads_boosts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('user_id');
            $table->foreignId('meta_audience_id')->nullable()->after('user_id')
                ->constrained('meta_audiences')->nullOnDelete();

            $table->string('objective')->nullable()->after('platform');
            $table->string('optimization_goal')->nullable()->after('objective');
            $table->string('billing_event')->nullable()->after('optimization_goal');
            $table->string('bid_strategy')->nullable()->after('billing_event');
            $table->decimal('bid_amount', 10, 2)->nullable()->after('bid_strategy');

            // daily | lifetime — `budget` reste le montant demande, dans la
            // devise du compte ; c'est ce champ qui dit ce qu'il signifie.
            $table->string('budget_type')->default('lifetime')->after('budget');

            // Copie figee du ciblage envoye a Meta.
            $table->json('targeting')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_boosts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_audience_id');
            $table->dropColumn([
                'name', 'objective', 'optimization_goal', 'billing_event',
                'bid_strategy', 'bid_amount', 'budget_type', 'targeting',
            ]);
        });
    }
};
