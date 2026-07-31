<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprime l'ancien système de templates média (GD/Intervention), remplacé par le
 * système de briques carrousel HTML/CSS (config/carousel.php + CarouselRenderService).
 *
 * On CONSERVE les colonnes visual_overlay_* sur threads (toujours utilisées par la
 * feature overlay, désormais rendue via une brique). Seuls la FK media_template_id et
 * la table media_templates disparaissent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_template_id');
        });

        Schema::dropIfExists('media_templates');
    }

    public function down(): void
    {
        Schema::create('media_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('format', 30);
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(1500);
            $table->string('layout', 30)->default('overlay');
            $table->string('title_font')->default('Montserrat');
            $table->string('title_font_weight', 20)->default('ExtraBold');
            $table->unsignedSmallInteger('title_font_size')->default(52);
            $table->string('body_font')->nullable();
            $table->string('body_font_weight', 20)->nullable();
            $table->unsignedSmallInteger('body_font_size')->nullable();
            $table->json('colors');
            $table->json('border')->nullable();
            $table->json('config')->nullable();
            $table->string('preview_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->foreignId('media_template_id')->nullable()->after('visual_overlay_subtitle')
                ->constrained('media_templates')->nullOnDelete();
        });
    }
};
