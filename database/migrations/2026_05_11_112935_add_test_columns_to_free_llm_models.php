<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('free_llm_models', function (Blueprint $table) {
            $table->string('last_test_status', 32)->nullable()->after('is_available');
            $table->string('last_test_error', 500)->nullable()->after('last_test_status');
            $table->unsignedInteger('last_test_latency_ms')->nullable()->after('last_test_error');
            $table->timestamp('last_tested_at')->nullable()->after('last_test_latency_ms');

            $table->index(['supports_text', 'last_test_status']);
            $table->index(['supports_vision', 'last_test_status']);
        });
    }

    public function down(): void
    {
        Schema::table('free_llm_models', function (Blueprint $table) {
            $table->dropIndex(['supports_text', 'last_test_status']);
            $table->dropIndex(['supports_vision', 'last_test_status']);
            $table->dropColumn(['last_test_status', 'last_test_error', 'last_test_latency_ms', 'last_tested_at']);
        });
    }
};
