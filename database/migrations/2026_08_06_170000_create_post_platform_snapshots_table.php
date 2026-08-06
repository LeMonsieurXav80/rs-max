<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historique des métriques d'une publication.
     *
     * `post_platform.metrics` ne conserve que la dernière valeur : les relevés
     * intermédiaires étaient écrasés à chaque sync. Cette table les conserve
     * pour reconstituer la courbe de montée d'un post (la vélocité des
     * premières heures étant le signal le plus prédictif de la portée finale).
     *
     * Aucun appel API supplémentaire : on stocke ce que `stats:sync` récupère déjà.
     */
    public function up(): void
    {
        Schema::create('post_platform_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_platform_id')->constrained('post_platform')->cascadeOnDelete();
            $table->timestamp('measured_at');

            // Métriques communes à toutes les plateformes (nullable : toutes ne les fournissent pas)
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('bookmarks')->nullable();
            $table->unsignedBigInteger('followers')->nullable();

            // Payload complet, pour les métriques spécifiques à une plateforme
            $table->json('metrics')->nullable();

            $table->index(['post_platform_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_platform_snapshots');
    }
};
