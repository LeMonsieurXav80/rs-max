<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->string('conversation_key')->nullable()->after('parent_id')->index();
        });

        // Backfill existing items
        // DMs: group by chat/conversation (external_post_id)
        DB::table('inbox_items')
            ->where('type', 'dm')
            ->whereNotNull('external_post_id')
            ->update(['conversation_key' => DB::raw("CONCAT('dm:', external_post_id)")]);

        // Replies with parent_id: same conversation as parent
        DB::table('inbox_items')
            ->whereNotNull('parent_id')
            ->whereNull('conversation_key')
            ->update(['conversation_key' => DB::raw('parent_id')]);

        // Remaining items: each is its own conversation
        DB::table('inbox_items')
            ->whereNull('conversation_key')
            ->update(['conversation_key' => DB::raw('external_id')]);
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn('conversation_key');
        });
    }
};
