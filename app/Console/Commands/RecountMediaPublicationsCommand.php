<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Models\MediaPublication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecountMediaPublicationsCommand extends Command
{
    protected $signature = 'media:recount-publications
        {--dry-run : Affiche ce qui serait fait sans rien écrire}
        {--reset : Vide media_publications avant rebuild (perd le contexte des entrées créées par le tracker)}';

    protected $description = 'Reconstruit media_publications depuis posts.media + thread_segments.media et resynchronise media_files.publication_count.';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $reset = $this->option('reset');

        if ($reset && ! $dry) {
            if (! $this->confirm('--reset va vider media_publications. Confirmer ?')) {
                $this->warn('Annulé.');

                return self::FAILURE;
            }
            MediaPublication::query()->delete();
            $this->info('media_publications vidée.');
        }

        $created = 0;
        $skipped = 0;
        $unknown = 0;

        // 1) Posts publiés
        DB::table('posts')
            ->where('status', 'published')
            ->whereNotNull('media')
            ->where('media', '!=', '[]')
            ->orderBy('id')
            ->chunk(200, function ($posts) use (&$created, &$skipped, &$unknown, $dry) {
                foreach ($posts as $post) {
                    $media = json_decode($post->media, true);
                    if (! is_array($media)) {
                        continue;
                    }
                    $publishedAt = $post->published_at ?? $post->updated_at;
                    foreach ($media as $item) {
                        [$status] = $this->backfillOne(
                            url: $item['url'] ?? null,
                            publishedAt: $publishedAt,
                            postId: $post->id,
                            threadSegmentId: null,
                            context: 'backfill:post',
                            dry: $dry,
                        );
                        $$status++;
                    }
                }
            });

        // 2) Thread segments dont le parent thread est publié
        DB::table('thread_segments as ts')
            ->join('threads as t', 't.id', '=', 'ts.thread_id')
            ->where('t.status', 'published')
            ->whereNotNull('ts.media')
            ->where('ts.media', '!=', '[]')
            ->select('ts.id', 'ts.media', 't.published_at as thread_published_at', 'ts.updated_at')
            ->orderBy('ts.id')
            ->chunk(200, function ($segments) use (&$created, &$skipped, &$unknown, $dry) {
                foreach ($segments as $seg) {
                    $media = json_decode($seg->media, true);
                    if (! is_array($media)) {
                        continue;
                    }
                    $publishedAt = $seg->thread_published_at ?? $seg->updated_at;
                    foreach ($media as $item) {
                        [$status] = $this->backfillOne(
                            url: $item['url'] ?? null,
                            publishedAt: $publishedAt,
                            postId: null,
                            threadSegmentId: $seg->id,
                            context: 'backfill:thread_segment',
                            dry: $dry,
                        );
                        $$status++;
                    }
                }
            });

        $this->info("Backfill : $created créées · $skipped déjà présentes · $unknown URLs sans MediaFile correspondant");

        // 3) Resync publication_count = COUNT(media_publications)
        if (! $dry) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('
                    UPDATE media_files mf
                    SET publication_count = (
                        SELECT COUNT(*) FROM media_publications mp WHERE mp.media_file_id = mf.id
                    )
                ');
            } else {
                DB::statement('
                    UPDATE media_files
                    SET publication_count = (
                        SELECT COUNT(*) FROM media_publications mp WHERE mp.media_file_id = media_files.id
                    )
                ');
            }
            $resynced = MediaFile::where('publication_count', '>', 0)->count();
            $this->info("publication_count resynchronisé. $resynced photos avec count > 0.");
        } else {
            $this->warn('--dry-run : publication_count NON modifié.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: 'created'|'skipped'|'unknown', 1: ?int}  Statut + id MediaPublication créé/existant
     */
    private function backfillOne(
        ?string $url,
        $publishedAt,
        ?int $postId,
        ?int $threadSegmentId,
        string $context,
        bool $dry,
    ): array {
        if (! is_string($url) || $url === '') {
            return ['unknown', null];
        }
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($filename === '') {
            return ['unknown', null];
        }
        $mediaFile = MediaFile::where('filename', $filename)->first(['id']);
        if (! $mediaFile) {
            return ['unknown', null];
        }

        // Idempotence : (media_file_id, post_id) ou (media_file_id, thread_segment_id)
        $existsQuery = MediaPublication::where('media_file_id', $mediaFile->id);
        if ($postId !== null) {
            $existsQuery->where('post_id', $postId);
        } else {
            $existsQuery->whereNull('post_id')->where('thread_segment_id', $threadSegmentId);
        }
        if ($existsQuery->exists()) {
            return ['skipped', null];
        }

        if ($dry) {
            return ['created', null];
        }

        $pub = MediaPublication::create([
            'media_file_id' => $mediaFile->id,
            'post_id' => $postId,
            'thread_segment_id' => $threadSegmentId,
            'published_at' => $publishedAt ?? now(),
            'context' => $context,
        ]);

        return ['created', $pub->id];
    }
}
