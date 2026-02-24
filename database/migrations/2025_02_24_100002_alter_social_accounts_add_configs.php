<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('language', 10)->default('fr')->after('credentials');
            $table->text('branding')->nullable()->after('language');
            $table->boolean('show_branding')->default(false)->after('branding');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['language', 'branding', 'show_branding']);
        });
    }
};
