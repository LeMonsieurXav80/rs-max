<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Models\Platform;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ecarte les reponses Bluesky importees avant que le filtre n'existe.
 *
 * Le flux d'auteur rend les reponses melangees aux publications. L'import les
 * exclut desormais a la source, mais les lignes deja en base ont ete ramenees
 * sans ce filtre : chaque « Merci beaucoup ! » etait devenu une publication
 * RS-Max a part entiere.
 *
 * La longueur du texte ne suffit pas a trancher — une vraie publication peut
 * etre courte. On interroge donc l'API, qui dit si un post repond a un autre
 * (`record.reply`). L'endpoint est public, gratuit, et accepte 25 identifiants
 * par appel.
 *
 * Une publication RS-Max n'est defaite que si TOUTES ses diffusions sont des
 * reponses : une reponse Bluesky groupee par erreur avec une vraie publication
 * d'un autre reseau merite un examen humain, pas une suppression.
 */
class PurgeBlueskyRepliesCommand extends Command
{
    protected $signature = 'bluesky:purge-replies
                            {--days=180 : Profondeur a examiner}
                            {--commit : Executer ; sans ce drapeau, affiche le plan}';

    protected $description = 'Ecarte du flux les reponses Bluesky prises pour des publications';

    private const API_BASE = 'https://public.api.bsky.app';

    /** Maximum accepte par `getPosts`. */
    private const CHUNK = 25;

    /**
     * RS-Max stocke l'identifiant Bluesky sous la forme `at://...|cid`, le cid
     * servant a republier. L'API, elle, n'accepte que l'at-uri seul.
     */
    private function atUri(ExternalPost $post): string
    {
        return explode('|', (string) $post->external_id)[0];
    }

    /**
     * Repond-il a QUELQU'UN D'AUTRE ?
     *
     * `record.reply` ne suffit pas a trancher : Bluesky le pose aussi sur les
     * suites de fil, ou l'auteur se repond a lui-meme. Celles-la sont du
     * contenu a part entiere, pas du bruit — les confondre reviendrait a
     * supprimer la moitie d'un fil publie nativement.
     */
    private function repondAUnTiers(array $post): bool
    {
        $parent = $post['record']['reply']['parent']['uri'] ?? null;

        if (! $parent) {
            return false;
        }

        $auteur = $post['author']['did'] ?? null;
        // at://did:plc:xxxx/app.bsky.feed.post/yyy
        $auteurDuParent = explode('/', str_replace('at://', '', $parent))[0] ?? null;

        return $auteur !== null && $auteurDuParent !== null && $auteur !== $auteurDuParent;
    }

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $platform = Platform::where('slug', 'bluesky')->first();

        if (! $platform) {
            $this->error('Bluesky absent des plateformes.');

            return self::FAILURE;
        }

        $posts = ExternalPost::where('platform_id', $platform->id)
            ->whereNull('ignored_at')
            ->where('published_at', '>=', now()->subDays((int) $this->option('days')))
            ->get();

        if ($posts->isEmpty()) {
            $this->info('Rien a examiner.');

            return self::SUCCESS;
        }

        $this->line("{$posts->count()} publication(s) Bluesky a verifier aupres de l'API.");

        $reponses = collect();
        $echecs = 0;
        $bar = $this->output->createProgressBar((int) ceil($posts->count() / self::CHUNK));
        $bar->start();

        foreach ($posts->chunk(self::CHUNK) as $lot) {
            $bar->advance();

            // XRPC attend `uris=` repete, pas `uris[0]=` : le tableau passe
            // a Http::get() produit la seconde forme, que l'API refuse.
            $query = collect($lot)
                ->map(fn (ExternalPost $p) => 'uris='.urlencode($this->atUri($p)))
                ->implode('&');

            $reponse = Http::timeout(30)->get(self::API_BASE.'/xrpc/app.bsky.feed.getPosts?'.$query);

            if (! $reponse->successful()) {
                $echecs++;

                continue;
            }

            $parUri = collect($reponse->json('posts', []))->keyBy('uri');

            foreach ($lot as $externalPost) {
                $distant = $parUri->get($this->atUri($externalPost));

                if ($distant && $this->repondAUnTiers($distant)) {
                    $reponses->push($externalPost);
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Sans ca, une API qui refuse toutes les requetes se lit « aucune
        // reponse trouvee » — exactement ce qui s'est produit la premiere fois.
        if ($echecs > 0) {
            $this->error("{$echecs} lot(s) refuse(s) par l'API : resultat incomplet, ne pas s'y fier.");
        }

        if ($reponses->isEmpty()) {
            $this->info('Aucune reponse trouvee.');

            return self::SUCCESS;
        }

        // Une publication n'est defaite que si elle n'est faite QUE de reponses.
        $parPost = $reponses->whereNotNull('adopted_post_id')->groupBy('adopted_post_id');
        $aDefaire = [];

        foreach ($parPost as $postId => $cartes) {
            $total = ExternalPost::where('adopted_post_id', $postId)->count();

            if ($total === $cartes->count()) {
                $aDefaire[] = (int) $postId;
            }
        }

        $this->info($reponses->count().' reponse(s) identifiee(s), dont '
            .$reponses->whereNotNull('adopted_post_id')->count().' deja adoptee(s).');
        $this->info(count($aDefaire).' publication(s) RS-Max a defaire.');

        $melangees = $parPost->count() - count($aDefaire);

        if ($melangees > 0) {
            $this->warn("{$melangees} publication(s) melangent reponse et vraie publication : laissees en l'etat.");
        }

        if (! $commit) {
            $this->comment('Aucune ecriture : relancer avec --commit pour executer.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($reponses, $aDefaire) {
            foreach ($aDefaire as $postId) {
                $post = Post::find($postId);

                if (! $post || $post->source_type !== 'native') {
                    continue;
                }

                $post->partners()->detach();
                $post->postPlatforms()->delete();
                $post->delete();
            }

            ExternalPost::whereIn('id', $reponses->pluck('id'))->update([
                'adopted_post_id' => null,
                'adopted_at' => null,
                'group_key' => null,
                'ignored_at' => now(),
                'ignored_reason' => ExternalPost::IGNORED_AUTO_NOISE,
            ]);
        });

        $this->info('Fait.');

        return self::SUCCESS;
    }
}
