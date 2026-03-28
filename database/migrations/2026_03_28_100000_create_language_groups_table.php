<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('languages'); // ['fr', 'en', 'es', 'de', ...]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_groups');
    }
};
