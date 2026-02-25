<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the column to hold JSON strings
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('language', 255)->default('["fr"]')->change();
        });

        // Migrate existing data to JSON format
        DB::table('social_accounts')->where('language', 'both')->update(['language' => '["fr","en"]']);
        DB::table('social_accounts')->where('language', 'fr')->update(['language' => '["fr"]']);
        DB::table('social_accounts')->where('language', 'en')->update(['language' => '["en"]']);

        // Rename column
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->renameColumn('language', 'languages');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->renameColumn('languages', 'language');
        });

        DB::table('social_accounts')->where('language', '["fr","en"]')->update(['language' => 'both']);
        DB::table('social_accounts')->where('language', '["fr"]')->update(['language' => 'fr']);
        DB::table('social_accounts')->where('language', '["en"]')->update(['language' => 'en']);

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('language', 10)->default('fr')->change();
        });
    }
};
