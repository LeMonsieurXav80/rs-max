<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_search_terms', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_search_terms', 'purpose')) {
                $table->string('purpose', 20)->default('likes')->after('term');
            }
            if (! Schema::hasColumn('bot_search_terms', 'max_per_run')) {
                $table->unsignedInteger('max_per_run')->nullable()->after('max_likes_per_run');
            }
        });

        DB::table('bot_search_terms')->whereNull('purpose')->orWhere('purpose', '')->update(['purpose' => 'likes']);

        $oldIdx = 'bot_search_terms_social_account_id_term_unique';
        $newIdx = 'bot_search_terms_social_account_id_term_purpose_unique';

        // Ordre important sur MySQL : creer le nouveau index AVANT de drop l'ancien,
        // car la FK sur social_account_id a besoin d'un index couvrant cette colonne en permanence.
        if (! Schema::hasIndex('bot_search_terms', $newIdx)) {
            Schema::table('bot_search_terms', function (Blueprint $table) {
                $table->unique(['social_account_id', 'term', 'purpose']);
            });
        }

        if (Schema::hasIndex('bot_search_terms', $oldIdx)) {
            Schema::table('bot_search_terms', function (Blueprint $table) {
                $table->dropUnique(['social_account_id', 'term']);
            });
        }
    }

    public function down(): void
    {
        $oldIdx = 'bot_search_terms_social_account_id_term_unique';
        $newIdx = 'bot_search_terms_social_account_id_term_purpose_unique';

        if (Schema::hasIndex('bot_search_terms', $newIdx)) {
            Schema::table('bot_search_terms', function (Blueprint $table) {
                $table->dropUnique(['social_account_id', 'term', 'purpose']);
            });
        }

        if (! Schema::hasIndex('bot_search_terms', $oldIdx)) {
            Schema::table('bot_search_terms', function (Blueprint $table) {
                $table->unique(['social_account_id', 'term']);
            });
        }

        Schema::table('bot_search_terms', function (Blueprint $table) {
            if (Schema::hasColumn('bot_search_terms', 'purpose')) {
                $table->dropColumn('purpose');
            }
            if (Schema::hasColumn('bot_search_terms', 'max_per_run')) {
                $table->dropColumn('max_per_run');
            }
        });
    }
};
