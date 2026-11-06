<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('profile', 40);
            $table->string('kind', 30);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['profile', 'status']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('browser_profile', 40)->nullable()->after('driver');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_tasks');

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('browser_profile');
        });
    }
};
