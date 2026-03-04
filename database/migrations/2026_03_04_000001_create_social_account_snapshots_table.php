<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('granularity', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->unsignedInteger('followers_count');
            $table->timestamp('created_at')->nullable();

            $table->unique(['social_account_id', 'date', 'granularity'], 'snapshots_account_date_gran_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_snapshots');
    }
};
