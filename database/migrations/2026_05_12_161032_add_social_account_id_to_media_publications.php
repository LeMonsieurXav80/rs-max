<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_publications', function (Blueprint $table) {
            // Permet de filtrer/compter les publications par compte sans avoir à passer
            // par post_platform (qui n'existe pas pour les threads).
            $table->foreignId('social_account_id')->nullable()->after('post_platform_id')
                ->constrained('social_accounts')->nullOnDelete();
            $table->index(['media_file_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('media_publications', function (Blueprint $table) {
            $table->dropIndex(['media_file_id', 'social_account_id']);
            $table->dropConstrainedForeignId('social_account_id');
        });
    }
};
