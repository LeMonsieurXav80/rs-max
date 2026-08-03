<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->string('color', 7)->default('#f59e0b');
            $table->boolean('is_active')->default(true);
            // Provenance de la fiche : creee a la main, importee des anciennes
            // marques texte, ou detectee par l'IA vision sur une photo.
            $table->string('origin', 20)->default('manual');
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('media_file_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['media_file_id', 'partner_id']);
            $table->index('partner_id');
        });

        Schema::create('partner_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            // 'auto' = herite d'une photo taguee, recalcule a chaque save du post.
            // 'manual' = pose par un humain, jamais ecrase par le recalcul.
            $table->string('source', 10)->default('manual');
            $table->timestamps();

            $table->unique(['post_id', 'partner_id']);
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_post');
        Schema::dropIfExists('media_file_partner');
        Schema::dropIfExists('partners');
    }
};
