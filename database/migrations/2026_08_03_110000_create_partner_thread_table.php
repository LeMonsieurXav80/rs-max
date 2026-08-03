<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meme tag partenaire que pour les publications, applique aux fils de discussion.
 * Les tags 'auto' se deduisent des photos de TOUS les segments du fil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_thread', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('manual');
            $table->timestamps();

            $table->unique(['thread_id', 'partner_id']);
            $table->index('partner_id');
        });

        $this->tagExistingThreads();
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_thread');
    }

    /**
     * Retag les fils existants d'apres les photos deja taguees de leurs segments.
     */
    private function tagExistingThreads(): void
    {
        $partnersByFilename = [];
        DB::table('media_file_partner')
            ->join('media_files', 'media_files.id', '=', 'media_file_partner.media_file_id')
            ->select('media_files.id', 'media_files.filename', 'media_file_partner.partner_id')
            ->orderBy('media_files.id')
            ->chunk(500, function ($rows) use (&$partnersByFilename) {
                foreach ($rows as $row) {
                    $partnersByFilename[$row->filename][] = $row->partner_id;
                    $partnersByFilename[(string) $row->id][] = $row->partner_id;
                }
            });

        if (empty($partnersByFilename)) {
            return;
        }

        $now = now();

        DB::table('thread_segments')
            ->select('thread_id', 'media')
            ->whereNotNull('media')
            ->orderBy('id')
            ->chunk(300, function ($rows) use ($partnersByFilename, $now) {
                $found = [];

                foreach ($rows as $row) {
                    $media = json_decode($row->media ?? '', true);
                    if (! is_array($media)) {
                        continue;
                    }

                    foreach ($media as $item) {
                        $keys = [];
                        if (is_array($item) && ! empty($item['id'])) {
                            $keys[] = (string) $item['id'];
                        }
                        $url = is_string($item) ? $item : ($item['url'] ?? '');
                        $filename = basename((string) parse_url((string) $url, PHP_URL_PATH));
                        if ($filename !== '') {
                            $keys[] = $filename;
                        }

                        foreach ($keys as $key) {
                            foreach ($partnersByFilename[$key] ?? [] as $partnerId) {
                                $found[$row->thread_id][$partnerId] = true;
                            }
                        }
                    }
                }

                $pivot = [];
                foreach ($found as $threadId => $partnerIds) {
                    foreach (array_keys($partnerIds) as $partnerId) {
                        $pivot[] = [
                            'thread_id' => $threadId,
                            'partner_id' => $partnerId,
                            'source' => 'auto',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($pivot, 200) as $chunk) {
                    DB::table('partner_thread')->insertOrIgnore($chunk);
                }
            });
    }
};
