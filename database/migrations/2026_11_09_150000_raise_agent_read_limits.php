<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_account_actions')
            ->whereIn('action', ['feed.read', 'search.read', 'thread.read', 'profile.read', 'mentions.read', 'comments.read', 'video.comments'])
            ->whereBetween('daily_limit', [1, 999])
            ->update(['daily_limit' => 1000]);
    }

    public function down(): void
    {
        // Le plafond reste modifiable par compte ; les anciennes valeurs n'ont pas été conservées.
    }
};
