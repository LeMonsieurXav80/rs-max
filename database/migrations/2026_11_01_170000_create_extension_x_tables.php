<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_x_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('username', 15)->unique();
            $t->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
        });
        Schema::create('extension_x_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $t->string('tweet_id', 25);
            $t->string('author', 15);
            $t->longText('context_json');
            $t->char('persona_hash', 64);
            $t->text('reply_text');
            $t->string('status', 20)->default('prepared');
            $t->boolean('liked')->default(false);
            $t->string('proof', 255)->nullable();
            $t->timestamps();
            $t->unique(['social_account_id', 'tweet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_x_replies');
        Schema::dropIfExists('extension_x_profiles');
    }
};
