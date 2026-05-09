<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('reshare_source_post_platform_id')
                ->nullable()
                ->after('source_type')
                ->constrained('post_platform')
                ->nullOnDelete();

            $table->foreignId('reshare_source_platform_id')
                ->nullable()
                ->after('reshare_source_post_platform_id')
                ->constrained('platforms')
                ->nullOnDelete();

            $table->string('reshare_source_external_id')->nullable()->after('reshare_source_platform_id');
            $table->string('reshare_source_url', 1024)->nullable()->after('reshare_source_external_id');
            $table->string('reshare_mode', 32)->nullable()->after('reshare_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['reshare_source_post_platform_id']);
            $table->dropForeign(['reshare_source_platform_id']);
            $table->dropColumn([
                'reshare_source_post_platform_id',
                'reshare_source_platform_id',
                'reshare_source_external_id',
                'reshare_source_url',
                'reshare_mode',
            ]);
        });
    }
};
