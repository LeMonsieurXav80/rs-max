<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lien entre une publication RS-Max et la campagne Meta qui la sponsorise.
     *
     * Sans cette table, un boost lance par l'agent devient introuvable : Meta ne
     * sait pas d'ou vient la campagne, et RS-Max ne sait pas qu'un de ses posts
     * est sponsorise. C'est ce qui permet ensuite d'ajuster ou d'arreter la
     * promotion depuis le post, sans repasser par le Gestionnaire de publicites.
     */
    public function up(): void
    {
        Schema::create('meta_ads_boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_platform_id')->nullable()->constrained('post_platform')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Ce qui est sponsorise, cote Meta.
            $table->string('platform');          // facebook | instagram
            $table->string('object_story_id')->nullable();          // FB : {page_id}_{post_id}
            $table->string('instagram_media_id')->nullable();       // IG : media id

            // Les quatre objets crees, dans l'ordre de creation (donc de rollback).
            $table->string('campaign_id')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('creative_id')->nullable();
            $table->string('ad_id')->nullable();

            $table->decimal('budget', 10, 2);    // total, devise du compte
            $table->unsignedSmallInteger('days');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('status')->default('paused');  // paused | active | failed
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('post_platform_id');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_boosts');
    }
};
