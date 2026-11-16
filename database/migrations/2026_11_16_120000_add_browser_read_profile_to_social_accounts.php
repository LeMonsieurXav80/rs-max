<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Profil navigateur des lectures qu'un téléphone ne sait pas faire (relevé Studio web). */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('browser_read_profile', 40)->nullable()->after('browser_profile');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('browser_read_profile');
        });
    }
};
