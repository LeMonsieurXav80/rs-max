<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('source_context');
            $table->string('region', 120)->nullable()->after('city');
            $table->string('country', 120)->nullable()->after('region');
            $table->json('brands')->nullable()->after('country');
            $table->string('event', 200)->nullable()->after('brands');
            $table->timestamp('taken_at')->nullable()->after('event');
            $table->unsignedInteger('publication_count')->default(0)->after('taken_at');

            $table->index('city');
            $table->index('country');
            $table->index('taken_at');
            $table->index('publication_count');
        });

        // Backfill du compteur depuis media_publications pour ne pas casser les
        // photos déjà publiées avant l'ajout de la colonne.
        if (Schema::hasTable('media_publications')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('
                    UPDATE media_files mf
                    SET publication_count = (
                        SELECT COUNT(*) FROM media_publications mp WHERE mp.media_file_id = mf.id
                    )
                ');
            } else {
                // SQLite : pas de UPDATE ... FROM, on passe par une sous-requête corrélée.
                DB::statement('
                    UPDATE media_files
                    SET publication_count = (
                        SELECT COUNT(*) FROM media_publications mp WHERE mp.media_file_id = media_files.id
                    )
                ');
            }
        }
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropIndex(['country']);
            $table->dropIndex(['taken_at']);
            $table->dropIndex(['publication_count']);

            $table->dropColumn([
                'city',
                'region',
                'country',
                'brands',
                'event',
                'taken_at',
                'publication_count',
            ]);
        });
    }
};
