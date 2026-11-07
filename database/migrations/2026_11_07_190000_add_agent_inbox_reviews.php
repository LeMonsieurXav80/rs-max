<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $t) {
            $t->string('agent_review_reason', 80)->nullable();
            $t->timestamp('agent_reviewed_at')->nullable();
            $t->timestamp('agent_retry_at')->nullable();
            $t->index(['social_account_id', 'agent_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $t) {
            $t->dropIndex(['social_account_id', 'agent_retry_at']);
            $t->dropColumn(['agent_review_reason', 'agent_reviewed_at', 'agent_retry_at']);
        });
    }
};
