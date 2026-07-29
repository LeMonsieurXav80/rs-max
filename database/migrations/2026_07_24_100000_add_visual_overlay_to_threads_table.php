<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incrustation d'un titre + sous-titre sur la premiere image d'un fil
     * (post unique / carrousel). L'image composee est generee a la volee au
     * moment de publier puis supprimee ; seuls les parametres sont stockes ici.
     */
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->boolean('visual_overlay_enabled')->default(false)->after('instagram_compiled_content');
            $table->string('visual_overlay_title')->nullable()->after('visual_overlay_enabled');
            $table->string('visual_overlay_subtitle')->nullable()->after('visual_overlay_title');
            $table->foreignId('media_template_id')->nullable()->after('visual_overlay_subtitle')
                ->constrained('media_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_template_id');
            $table->dropColumn(['visual_overlay_enabled', 'visual_overlay_title', 'visual_overlay_subtitle']);
        });
    }
};
