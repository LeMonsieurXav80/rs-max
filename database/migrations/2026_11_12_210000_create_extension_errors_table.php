<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Erreurs JavaScript de Companion : la page « Erreurs » de Chrome n’est lisible que dans le navigateur. */
    public function up(): void
    {
        Schema::create('extension_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 40);
            $table->string('profile', 64)->nullable();
            $table->string('version', 20)->nullable();
            $table->string('context', 20);
            $table->string('message', 1000);
            $table->text('stack')->nullable();
            $table->string('page', 500)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_errors');
    }
};
