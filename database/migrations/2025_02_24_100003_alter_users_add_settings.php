<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('default_language', 10)->default('fr')->after('password');
            $table->boolean('auto_translate')->default(true)->after('default_language');
            $table->text('openai_api_key')->nullable()->after('auto_translate');
            $table->string('telegram_alert_chat_id')->nullable()->after('openai_api_key');
            $table->boolean('is_admin')->default(false)->after('telegram_alert_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['default_language', 'auto_translate', 'openai_api_key', 'telegram_alert_chat_id', 'is_admin']);
        });
    }
};
