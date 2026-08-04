<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Niveau d'abonnement de la plateforme (X : Basic, Premium, PremiumPlus).
     * Renseigné à la validation du compte ; null tant qu'on ne l'a pas interrogé.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('subscription_type', 32)->nullable()->after('platform_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('subscription_type');
        });
    }
};
