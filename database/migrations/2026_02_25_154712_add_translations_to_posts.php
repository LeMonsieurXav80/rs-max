<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('content_en');
        });

        // Migrate existing content_en into translations JSON
        $posts = DB::table('posts')->whereNotNull('content_en')->where('content_en', '!=', '')->get();
        foreach ($posts as $post) {
            DB::table('posts')->where('id', $post->id)->update([
                'translations' => json_encode(['en' => $post->content_en]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
