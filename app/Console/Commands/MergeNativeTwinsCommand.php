<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Models\Post;
use App\Services\Import\ExternalPostGrouper;
use App\Services\Import\PostMergeService;
use App\Services\Media\PerceptualHasher;
use Illuminate\Console\Command;

/**
 * Joint les publications deja adoptees qui n'en sont qu'une.
 *
 * Le regroupement de l'adoption agit AVANT la creation. Il ne peut rien pour
 * ce qui est deja en base : une epingle Pinterest adoptee hier, dont la
 * publication Instagram d'origine avait ete adoptee la semaine derniere.
 *
 * Le rapprochement se fait sur l'empreinte de l'image, jamais sur l'heure
 * seule : le reseau qui republie change le texte et l'horaire, mais pas la
 * photo. Les empreintes viennent de `external:hash-media`.
 *
 * Comme partout dans le depot, rien ne s'ecrit sans `--commit`.
 */
class MergeNativeTwinsCommand extends Command
{
    protected $signature = 'posts:merge-natives
                            {--days=180 : Profondeur a examiner}
                            {--hours= : Ecart maximal tolere entre deux jumelles}
                            {--commit : Executer ; sans ce drapeau, affiche le plan}';

    protected $description = 'Fusionne les publications adoptees qui sont la meme publication';

    /** Meme tolerance que la deduplication des photos rapatriees. */
    private const DHASH_TOLERANCE = 6;

    public function handle(PerceptualHasher $hasher, PostMergeService $merger): int
    {
        $commit = (bool) $this->option('commit');
        $hours = (int) ($this->option('hours') ?: ExternalPostGrouper::IMAGE_WINDOW_HOURS);

        $externals = ExternalPost::with('platform')
            ->whereNotNull('adopted_post_id')
            ->whereNotNull('media_hash')
            ->where('published_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('published_at')
            ->get();

        if ($externals->count() < 2) {
            $this->info('Pas assez de publications avec empreinte pour chercher des jumelles.');

            return self::SUCCESS;
        }

        $this->line("{$externals->count()} publication(s) adoptee(s) avec empreinte.");

        $pairs = [];
        $seen = [];

        foreach ($externals as $a) {
            foreach ($externals as $b) {
                if ($a->id >= $b->id
                    || $a->platform_id === $b->platform_id
                    || $a->adopted_post_id === $b->adopted_post_id) {
                    continue;
                }

                $key = min($a->adopted_post_id, $b->adopted_post_id).':'.max($a->adopted_post_id, $b->adopted_post_id);

                if (isset($seen[$key])) {
                    continue;
                }

                if ($a->published_at->diffInHours($b->published_at, absolute: true) > $hours) {
                    continue;
                }

                $distance = $hasher->distance($a->media_hash, $b->media_hash);

                if ($distance === null || $distance > self::DHASH_TOLERANCE) {
                    continue;
                }

                $seen[$key] = true;
                $pairs[] = [$a, $b, $distance];
            }
        }

        if ($pairs === []) {
            $this->info('Aucune jumelle trouvee.');

            return self::SUCCESS;
        }

        $rows = [];
        $fusionnees = 0;
        $refusees = 0;

        foreach ($pairs as [$a, $b, $distance]) {
            $postA = Post::with('postPlatforms.platform')->find($a->adopted_post_id);
            $postB = Post::with('postPlatforms.platform')->find($b->adopted_post_id);

            if (! $postA || ! $postB) {
                continue;
            }

            [$keep, $absorbed] = $merger->pick($postA, $postB);

            $ligne = [
                $a->platform->slug.' + '.$b->platform->slug,
                'ecart '.$a->published_at->diffInHours($b->published_at, absolute: true).'h',
                'distance '.$distance,
                "#{$keep->id} garde #{$absorbed->id}",
            ];

            if (! $commit) {
                $rows[] = array_merge($ligne, ['a fusionner']);
                $fusionnees++;

                continue;
            }

            try {
                $merger->merge($keep, $absorbed);
                $rows[] = array_merge($ligne, ['fusionnee']);
                $fusionnees++;
            } catch (\Throwable $e) {
                $rows[] = array_merge($ligne, [$e->getMessage()]);
                $refusees++;
            }
        }

        $this->table(['Reseaux', 'Ecart', 'Image', 'Publications', 'Resultat'], $rows);

        $this->info($commit
            ? "{$fusionnees} fusion(s) effectuee(s), {$refusees} refusee(s)."
            : "{$fusionnees} fusion(s) proposee(s).");

        if (! $commit) {
            $this->comment('Aucune ecriture : relancer avec --commit pour executer.');
        }

        return self::SUCCESS;
    }
}
