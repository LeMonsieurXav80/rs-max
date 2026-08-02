<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Briques de carrousel stockées en base : les gabarits de slide deviennent des
 * DONNÉES éditables depuis l'interface, au lieu d'être des fichiers Blade.
 *
 * `html` contient un gabarit HTML/CSS à marqueurs ({{ slot }}, {{#if}}, {{#each}}),
 * rendu SANS aucune exécution par App\Services\Carousel\TemplateRenderer.
 * Les briques fournies restent déclarées dans config/carousel.php ; le registre
 * fusionne les deux sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carousel_bricks', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // ['*'] ou une liste de clés de config('carousel.ratios')
            $table->json('ratios');
            // Slots typés, même format que le manifeste : key => [label, type, …]
            $table->json('slots');
            $table->longText('html');
            // Valeurs d'exemple pour l'aperçu de la galerie
            $table->json('sample_data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carousel_bricks');
    }
};
