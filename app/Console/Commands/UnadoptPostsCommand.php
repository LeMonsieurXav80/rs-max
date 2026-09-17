<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Defait une adoption : la publication RS-Max disparait, les publications
 * natives d'origine retournent dans le flux.
 *
 * Indispensable des lors que l'adoption est automatique. Un regroupement a
 * tort — deux marques differentes fusionnees parce que leurs textes anglais se
 * ressemblaient — ne se repare pas a la main : il faut rendre les cartes au
 * flux et laisser l'adoption corrigee refaire le travail.
 *
 * Ne touche QUE des publications natives : une publication emise par RS-Max
 * n'a pas ete adoptee, la defaire n'aurait aucun sens et detruirait du travail.
 */
class UnadoptPostsCommand extends Command
{
    protected $signature = 'posts:unadopt
                            {ids* : Identifiants des publications a rendre au flux}
                            {--commit : Executer ; sans ce drapeau, affiche le plan}';

    protected $description = 'Defait l\'adoption de publications natives';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $posts = Post::with('postPlatforms.platform')
            ->whereIn('id', $this->argument('ids'))
            ->get();

        if ($posts->isEmpty()) {
            $this->error('Aucune publication trouvee.');

            return self::FAILURE;
        }

        $rows = [];
        $traitees = 0;

        foreach ($posts as $post) {
            $reseaux = $post->postPlatforms->map(fn ($pp) => $pp->platform?->slug)->implode(', ');
            $cartes = ExternalPost::where('adopted_post_id', $post->id)->count();

            if ($post->source_type !== 'native') {
                $rows[] = ["#{$post->id}", $reseaux, $cartes, 'refuse : pas une publication native'];

                continue;
            }

            if ($commit) {
                DB::transaction(function () use ($post) {
                    ExternalPost::where('adopted_post_id', $post->id)->update([
                        'adopted_post_id' => null,
                        'adopted_at' => null,
                        'group_key' => null,
                    ]);

                    $post->partners()->detach();
                    $post->postPlatforms()->delete();
                    $post->delete();
                });
            }

            $traitees++;
            $rows[] = ["#{$post->id}", $reseaux, $cartes, $commit ? 'rendue au flux' : 'a rendre au flux'];
        }

        $this->table(['Publication', 'Reseaux', 'Cartes natives', 'Resultat'], $rows);

        if (! $commit) {
            $this->comment('Aucune ecriture : relancer avec --commit pour executer.');
        }

        return self::SUCCESS;
    }
}
