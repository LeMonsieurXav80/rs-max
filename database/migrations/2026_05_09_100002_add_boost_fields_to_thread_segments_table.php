<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_segments', function (Blueprint $table) {
            $table->boolean('is_boost')->default(false)->after('media');
            $table->foreignId('boost_source_thread_id')
                ->nullable()
                ->after('is_boost')
                ->constrained('threads')
                ->nullOnDelete();
            $table->string('boost_source_url', 1024)->nullable()->after('boost_source_thread_id');
        });
    }

    public function down(): void
    {
        Schema::table('thread_segments', function (Blueprint $table) {
            $table->dropForeign(['boost_source_thread_id']);
            $table->dropColumn(['is_boost', 'boost_source_thread_id', 'boost_source_url']);
        });
    }
};
