<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Les réponses préparées vivent sur le message : plus de seconde file à part. */
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->text('post_content')->nullable()->after('post_url');
            $table->text('reply_draft')->nullable()->after('reply_content');
        });

        // Les propositions TikTok n'ont pas de message : elles seront rédigées à nouveau.
        DB::table('agent_proposals')->where('status', 'pending')->whereNotNull('inbox_item_id')->get()
            ->each(fn ($p) => DB::table('inbox_items')->where('id', $p->inbox_item_id)->update(['reply_draft' => $p->reply_text]));

        Schema::dropIfExists('agent_proposals');
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn(['post_content', 'reply_draft']);
        });
    }
};
