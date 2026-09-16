<?php

namespace App\Console\Commands;

use App\Models\ExternalPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Import\ExternalPostGrouper;
use App\Services\Import\PostAdoptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Transforme en publications RS-Max les publications natives dont on est sur.
 *
 * Ne touche qu'aux groupes que `ExternalPostGrouper` juge surs : une jumelle
 * douteuse reste dans le flux de `/external`, ou l'oeil tranche en un clic.
 * Fusionner a tort deux publications distinctes se repare mal — les stats, le
 * comptage d'usage des photos et les tags partenaires suivraient la fusion.
 *
 * Comme partout dans le depot, on ne fait rien sans `--commit` : sans lui la
 * commande affiche le plan.
 *
 * Limite connue : le regroupement ne regarde ni le proprietaire du compte ni
 * la marque. Deux comptes sans rapport qui publieraient le meme texte a la
 * meme minute seraient fusionnes. Utiliser `--account` ou `--platform` si des
 * univers etrangers cohabitent dans la meme installation.
 */
class AutoAdoptExternalPostsCommand extends Command
{
    protected $signature = 'external:auto-adopt
                            {--days= : Profondeur du rattrapage}
                            {--min-age= : Delai de grace en minutes avant adoption}
                            {--platform=* : Restreindre a un ou plusieurs reseaux}
                            {--account=* : Restreindre a un ou plusieurs comptes, par id}
                            {--commit : Executer ; sans ce drapeau, affiche le plan}';

    protected $description = 'Adopte automatiquement les publications natives sans ambiguite';

    public function handle(ExternalPostGrouper $grouper, PostAdoptionService $adoption): int
    {
        $commit = (bool) $this->option('commit');
        $days = (int) ($this->option('days') ?: config('import.auto_adopt.days'));
        $minAge = (int) ($this->option('min-age') ?: config('import.auto_adopt.min_age_minutes'));

        $platforms = $this->option('platform') ?: config('import.auto_adopt.platforms');

        $posts = ExternalPost::with(['platform', 'socialAccount'])
            ->adoptable()
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $platforms))
            ->when($this->option('account'), fn ($q, $ids) => $q->whereIn('social_account_id', $ids))
            ->where('published_at', '>=', now()->subDays($days))
            // Le delai de grace : une publication trop fraiche risque d'avoir
            // des jumelles pas encore importees.
            ->where('published_at', '<=', now()->subMinutes($minAge))
            ->get();

        if ($posts->isEmpty()) {
            $this->info('Rien a adopter.');

            return self::SUCCESS;
        }

        $groups = $grouper->group($posts);

        if ($commit) {
            $grouper->persist($groups);
        }

        $adopted = 0;
        $deferred = 0;
        $rows = [];

        foreach ($groups as $group) {
            $networks = $group['posts']->map(fn (ExternalPost $p) => $p->platform->slug)->implode(', ');
            $excerpt = str($group['posts']->first()->content ?? '')->squish()->limit(45);

            if (! $group['confident']) {
                $deferred++;
                $rows[] = ['manuel', $networks, $excerpt, $group['reason']];

                continue;
            }

            $user = $this->ownerFor($group['posts']);

            if (! $user) {
                $deferred++;
                $rows[] = ['manuel', $networks, $excerpt, 'aucun utilisateur rattache'];

                continue;
            }

            if ($commit) {
                try {
                    $adoption->adopt($group['posts'], $user);
                } catch (\Throwable $e) {
                    $deferred++;
                    $rows[] = ['echec', $networks, $excerpt, $e->getMessage()];

                    continue;
                }
            }

            $adopted++;
            $rows[] = [$commit ? 'adopte' : 'a adopter', $networks, $excerpt, $group['reason']];
        }

        $this->table(['Decision', 'Reseaux', 'Debut du texte', 'Motif'], $rows);

        $this->info($commit
            ? "{$adopted} publication(s) creee(s), {$deferred} laissee(s) au flux manuel."
            : "{$adopted} publication(s) seraient creees, {$deferred} laissee(s) au flux manuel.");

        if (! $commit) {
            $this->comment('Aucune ecriture : relancer avec --commit pour executer.');
        }

        return self::SUCCESS;
    }

    /**
     * A qui appartient la publication creee. `posts.user_id` decide de qui la
     * voit et peut la modifier : la mettre au nom de celui qui gere deja le
     * compte social vaut mieux que de tout empiler sur l'admin.
     *
     * @param  Collection<int, ExternalPost>  $posts
     */
    private function ownerFor(Collection $posts): ?User
    {
        foreach ($posts as $post) {
            $account = $post->socialAccount;

            if (! $account instanceof SocialAccount) {
                continue;
            }

            $user = $account->users()->orderBy('users.id')->first();

            if ($user) {
                return $user;
            }
        }

        return User::where('role', 'admin')->orderBy('id')->first();
    }
}
