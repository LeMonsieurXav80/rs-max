<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Destinataire affiché par TikTok Studio quand un commentaire répond à quelqu’un d’autre que la vidéo. */
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->string('reply_to_username', 64)->nullable()->after('author_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn('reply_to_username');
        });
    }
};
