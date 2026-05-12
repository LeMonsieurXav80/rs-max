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

    protected $description = 'Reconstruit media_publications depuis posts.media + thread_segments.media (1 entrée par média × compte) et resynchronise media_files.publication_count.';

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

        // 1) Posts publiés — on crée 1 entrée par (média × post_platform) pour capturer le compte.
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
                    $platforms = DB::table('post_platform')
                        ->where('post_id', $post->id)
                        ->where('status', 'published')
                        ->get(['id', 'social_account_id', 'published_at']);

                    if ($platforms->isEmpty()) {
                        // Pas de plateforme publiée : on ne peut pas attribuer à un compte.
                        continue;
                    }

                    foreach ($platforms as $pp) {
                        $publishedAt = $pp->published_at ?? $post->published_at ?? $post->updated_at;
                        foreach ($media as $item) {
                            [$status] = $this->backfillOne(
                                url: $item['url'] ?? null,
                                publishedAt: $publishedAt,
                                postId: $post->id,
                                threadSegmentId: null,
                                postPlatformId: $pp->id,
                                socialAccountId: $pp->social_account_id,
                                context: 'backfill:post',
                                dry: $dry,
                            );
                            $$status++;
                        }
                    }
                }
            });

        // 2) Thread segments dont le parent thread est publié — 1 entrée par (média × thread_segment_platform).
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

                    $segPlatforms = DB::table('thread_segment_platform')
                        ->where('thread_segment_id', $seg->id)
                        ->where('status', 'published')
                        ->get(['social_account_id', 'published_at']);

                    if ($segPlatforms->isEmpty()) {
                        continue;
                    }

                    foreach ($segPlatforms as $sp) {
                        $publishedAt = $sp->published_at ?? $seg->thread_published_at ?? $seg->updated_at;
                        foreach ($media as $item) {
                            [$status] = $this->backfillOne(
                                url: $item['url'] ?? null,
                                publishedAt: $publishedAt,
                                postId: null,
                                threadSegmentId: $seg->id,
                                postPlatformId: null,
                                socialAccountId: $sp->social_account_id,
                                context: 'backfill:thread_segment',
                                dry: $dry,
                            );
                            $$status++;
                        }
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
     * @return array{0: 'created'|'skipped'|'unknown', 1: ?int} Statut + id MediaPublication créé/existant
     */
    private function backfillOne(
        ?string $url,
        $publishedAt,
        ?int $postId,
        ?int $threadSegmentId,
        ?int $postPlatformId,
        ?int $socialAccountId,
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

        // Idempotence : (media_file_id, post_id|thread_segment_id, social_account_id).
        // Permet 1 entrée par compte cible pour un même post/segment.
        $existsQuery = MediaPublication::where('media_file_id', $mediaFile->id)
            ->where('social_account_id', $socialAccountId);
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
            'post_platform_id' => $postPlatformId,
            'social_account_id' => $socialAccountId,
            'published_at' => $publishedAt ?? now(),
            'context' => $context,
        ]);

        return ['created', $pub->id];
    }
}
