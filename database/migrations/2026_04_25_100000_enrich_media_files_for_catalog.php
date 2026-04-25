<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->text('description_fr')->nullable()->after('source_url');
            $table->json('thematic_tags')->nullable()->after('description_fr');
            $table->json('embedding')->nullable()->after('thematic_tags');
            $table->string('embedding_model', 50)->nullable()->after('embedding');
            $table->string('pool_suggested', 32)->nullable()->after('embedding_model');
            $table->boolean('allow_wildycaro')->default(false)->after('pool_suggested');
            $table->boolean('allow_pdc_vantour')->default(false)->after('allow_wildycaro');
            $table->string('intimacy_level', 32)->default('semi_prive')->after('allow_pdc_vantour');
            $table->json('people_ids')->nullable()->after('intimacy_level');
            $table->json('ai_metadata')->nullable()->after('people_ids');
            $table->text('source_context')->nullable()->after('ai_metadata');
            $table->string('source_path', 512)->nullable()->after('source_context');
            $table->string('phash', 64)->nullable()->after('source_path');
            $table->boolean('pending_analysis')->default(true)->after('phash');
            $table->timestamp('ingested_at')->nullable()->after('pending_analysis');

            $table->index(['allow_wildycaro', 'allow_pdc_vantour', 'intimacy_level'], 'media_files_pool_filter_idx');
            $table->index('phash');
            $table->index('pending_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_files_pool_filter_idx');
            $table->dropIndex(['phash']);
            $table->dropIndex(['pending_analysis']);

            $table->dropColumn([
                'description_fr',
                'thematic_tags',
                'embedding',
                'embedding_model',
                'pool_suggested',
                'allow_wildycaro',
                'allow_pdc_vantour',
                'intimacy_level',
                'people_ids',
                'ai_metadata',
                'source_context',
                'source_path',
                'phash',
                'pending_analysis',
                'ingested_at',
            ]);
        });
    }
};
