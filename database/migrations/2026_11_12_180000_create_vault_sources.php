<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les dossiers du Vault qu’un compte peut citer dans ses réponses. Chaque serveur sert son propre Vault.
        Schema::create('vault_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('corpus', 31);
            $table->string('format', 20)->default('notes');
            $table->json('folders');
            $table->json('excluded_folders')->nullable();
            $table->json('brands')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('social_account_vault_source', function (Blueprint $table) {
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_source_id')->constrained()->cascadeOnDelete();
            $table->primary(['social_account_id', 'vault_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_vault_source');
        Schema::dropIfExists('vault_sources');
    }
};
