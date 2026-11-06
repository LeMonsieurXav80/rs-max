<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_key', 64);
            $table->json('target')->nullable();
            $table->string('author')->nullable();
            $table->json('conversation');
            $table->text('reply_text');
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Une conversation refusée ou déjà tentée n'est jamais proposée à nouveau.
            $table->unique(['social_account_id', 'target_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_proposals');
    }
};
