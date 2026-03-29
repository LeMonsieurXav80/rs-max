<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Format & dimensions
            $table->string('format', 30); // pinterest_pin, instagram_post, instagram_carousel, etc.
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(1500);

            // Layout type (predefined layout variations)
            $table->string('layout', 30)->default('overlay'); // overlay, split, bold_text, numbered, framed, collage

            // Fonts (Google Fonts family names)
            $table->string('title_font')->default('Montserrat');
            $table->string('title_font_weight', 20)->default('ExtraBold');
            $table->unsignedSmallInteger('title_font_size')->default(52);
            $table->string('body_font')->nullable();
            $table->string('body_font_weight', 20)->nullable();
            $table->unsignedSmallInteger('body_font_size')->nullable();

            // Colors
            $table->json('colors'); // {background, text, accent, overlay_opacity, title_band_color, title_band_opacity}

            // Border / frame
            $table->json('border')->nullable(); // {enabled, type: "none"|"solid"|"pattern", color, thickness, inner_padding, pattern_image}

            // Additional config
            $table->json('config')->nullable(); // Extra layout-specific config (gradient direction, text position, etc.)

            $table->string('preview_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_templates');
    }
};
