<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('filename')->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('source', 20)->default('upload');
            $table->text('source_url')->nullable();
            $table->timestamps();

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
