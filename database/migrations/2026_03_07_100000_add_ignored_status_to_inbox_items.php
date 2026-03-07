<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No schema change needed — 'ignored' is just a new value for the existing `status` varchar column.
        // This migration serves as documentation and can be used to bulk-update if needed.
    }

    public function down(): void
    {
        // Revert any 'ignored' items back to 'read'
        \App\Models\InboxItem::where('status', 'ignored')->update(['status' => 'read']);
    }
};
