<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Services\Media\PerceptualHasher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconnait qu'une meme publication a ete postee sur plusieurs reseaux.
 *
 * C'est le travail que l'oeil fait dans le flux d'adoption : cocher les quatre
 * cartes qui ne sont qu'une seule publication. Automatise, il n'a aucun moyen
 * sur : les reseaux re-encodent les photos (donc pas de comparaison d'octets
 * sans tout telecharger) et raccourcissent les textes chacun a leur facon.
 *
 * D'ou deux verdicts plutot qu'un : un groupe est soit SUR — il part en
 * adoption automatique — soit DOUTEUX, et il reste dans le flux pour arbitrage
 * humain. Un faux positif fusionnerait deux publications distinctes en une, ce
 * qui se repare mal ; un faux negatif ne coute qu'un clic.
 */
class ExternalPostGrouper
{
    public function __construct(private readonly PerceptualHasher $hasher) {}

    /** Groupes de comptes, charges une fois par passage. */
    private ?array $groupesParCompte = null;

    /**
     * Deux reseaux ne recoivent jamais la publication a la seconde pres :
     * publication manuelle en serie, file d'attente, decalage d'horloge.
     */
    public const WINDOW_MINUTES = 30;

    /**
     * Fenetre elargie, reservee aux publications dont l'IMAGE concorde.
     *
     * Pinterest republie le lendemain la photo d'Instagram, avec sa propre
     * description : ni l'heure ni le texte ne rapprochent les deux. Sur 126
     * epingles mesurees, une seule avait sa jumelle Instagram a moins de 30
     * minutes, 111 a moins de 36 heures.
     *
     * Elle ne s'applique JAMAIS sur la seule proximite horaire : avec 159
     * publications sur quatre mois, un tel intervalle trouve toujours quelque
     * chose. Il faut que les empreintes se repondent.
     */
    public const IMAGE_WINDOW_HOURS = 72;

    /** Meme tolerance que la deduplication des photos rapatriees. */
    private const DHASH_TOLERANCE = 6;

    /** Au-dela, les textes disent la meme chose. */
    private const SURE = 0.75;

    /** En dessous, ce sont deux publications differentes. */
    private const DOUBT = 0.45;

    /** Un texte plus court ne porte pas assez de matiere pour trancher. */
    private const MIN_LENGTH = 25;

    /**
     * @param  Collection<int, ExternalPost>  $posts  Publications adoptables.
     * @return Collection<int, array{key: string, posts: Collection<int, ExternalPost>, confident: bool, reason: string}>
     */
    public function group(Collection $posts): Collection
    {
        $remaining = $posts->sortBy('published_at')->values()->all();
        $groups = collect();

        while ($remaining !== []) {
            $seed = array_shift($remaining);
            $members = collect([$seed]);
            $confident = true;
            $reason = 'isole';

            foreach ($remaining as $index => $candidate) {
                $verdict = $this->compare($seed, $candidate);

                if ($verdict === 'unrelated') {
                    continue;
                }

                $members->push($candidate);
                unset($remaining[$index]);

                if ($verdict === 'twin') {
                    $reason = 'textes concordants';

                    continue;
                }

                // Rapproches sans certitude : tout le groupe passe en manuel.
                $confident = false;
                $reason = $verdict;
            }

            $remaining = array_values($remaining);

            // Deux cartes du meme reseau dans un groupe : l'adoption ne sait pas
            // en faire une publication (un texte par reseau, pas deux).
            if ($members->count() > $members->pluck('platform_id')->unique()->count()) {
                $confident = false;
                $reason = 'deux publications du meme reseau';
            }

            $groups->push([
                'key' => $this->keyFor($members),
                'posts' => $members->sortBy('published_at')->values(),
                'confident' => $confident,
                'reason' => $reason,
            ]);
        }

        return $groups;
    }

    /**
     * Ecrit le regroupement en base, pour que le flux manuel puisse pre-cocher
     * les jumelles et qu'on sache apres coup pourquoi un groupe a ete forme.
     *
     * @param  Collection<int, array{key: string, posts: Collection<int, ExternalPost>}>  $groups
     */
    public function persist(Collection $groups): void
    {
        foreach ($groups as $group) {
            if ($group['posts']->count() < 2) {
                continue;
            }

            ExternalPost::whereIn('id', $group['posts']->pluck('id'))
                ->update(['group_key' => $group['key']]);
        }
    }

    /**
     * @return 'twin'|'unrelated'|string Un verdict nuance renvoie sa raison.
     */
    private function compare(ExternalPost $a, ExternalPost $b): string
    {
        if ($a->published_at === null || $b->published_at === null) {
            return 'unrelated';
        }

        // Deux marques qui n'ont rien a voir ne publient pas la meme chose.
        // Sans ce garde-fou, un tweet de Van Tour a ete fusionne avec une
        // publication Bluesky de Planete de Caro : memes textes anglais
        // generes, meme minute, deux univers differents.
        if (! $this->memeUnivers($a, $b)) {
            return 'unrelated';
        }

        $ecart = $a->published_at->diffInMinutes($b->published_at, absolute: true);

        // L'image d'abord : c'est le seul signal qui survit a une republication
        // automatique d'un reseau vers un autre, ou tout le reste change.
        if ($a->platform_id !== $b->platform_id && $this->sameImage($a, $b)) {
            return $ecart <= self::IMAGE_WINDOW_HOURS * 60
                ? 'twin'
                : 'unrelated';
        }

        if ($ecart > self::WINDOW_MINUTES) {
            return 'unrelated';
        }

        $left = $this->normalize($a->content);
        $right = $this->normalize($b->content);

        // Deux publications sans texte, a la meme heure : peut-etre la meme
        // photo, peut-etre deux photos d'une meme serie. Rien ne permet de
        // trancher sans telecharger les images.
        if (mb_strlen($left) < self::MIN_LENGTH || mb_strlen($right) < self::MIN_LENGTH) {
            return $this->sameMediaShape($a, $b)
                ? 'textes trop courts, memes medias'
                : 'unrelated';
        }

        $score = $this->similarity($left, $right);

        return match (true) {
            $score >= self::SURE => 'twin',
            $score >= self::DOUBT => 'textes proches sans certitude',
            default => 'unrelated',
        };
    }

    /**
     * Ramene deux redactions du meme message a une base comparable : chaque
     * reseau a ses liens raccourcis, ses mentions et son paquet de hashtags en
     * fin de texte, qui diraient « different » pour un message identique.
     */
    private function normalize(?string $content): string
    {
        $text = Str::lower((string) $content);
        $text = preg_replace('#https?://\S+#u', ' ', $text);
        $text = preg_replace('/[@#]\S+/u', ' ', $text);
        $text = Str::ascii($text);
        $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * similar_text est quadratique : au-dela de quelques milliers de caracteres
     * il coute cher pour rien, l'ouverture suffit a reconnaitre un doublon.
     */
    private function similarity(string $a, string $b): float
    {
        $a = mb_substr($a, 0, 2000);
        $b = mb_substr($b, 0, 2000);

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * Les deux comptes appartiennent-ils au meme univers ?
     *
     * Les groupes de comptes (`account_groups`) disent deja quelles marques
     * vont ensemble : c'est la seule source de verite disponible, et elle est
     * tenue a jour par l'utilisateur.
     *
     * Un compte rattache a AUCUN groupe ne bloque rien — on ne sait pas, donc
     * on laisse les autres criteres decider. Ce sont deux comptes groupes
     * SANS groupe commun qui trahissent deux univers distincts.
     */
    private function memeUnivers(ExternalPost $a, ExternalPost $b): bool
    {
        if ($a->social_account_id === $b->social_account_id) {
            return true;
        }

        $groupes = $this->groupesParCompte();

        $ga = $groupes[$a->social_account_id] ?? [];
        $gb = $groupes[$b->social_account_id] ?? [];

        if ($ga === [] || $gb === []) {
            return true;
        }

        return array_intersect($ga, $gb) !== [];
    }

    /**
     * @return array<int, array<int,int>> ids de groupes, par compte social
     */
    private function groupesParCompte(): array
    {
        if ($this->groupesParCompte !== null) {
            return $this->groupesParCompte;
        }

        $this->groupesParCompte = [];

        foreach (DB::table('account_group_social_account')->get() as $ligne) {
            $this->groupesParCompte[(int) $ligne->social_account_id][] = (int) $ligne->account_group_id;
        }

        return $this->groupesParCompte;
    }

    /**
     * Les deux publications montrent-elles la meme photo ?
     *
     * Comparaison d'empreintes perceptuelles : le reseau qui republie
     * re-encode l'image, donc les octets different alors que la photo est la
     * meme. Les empreintes sont posees par `external:hash-media` ; sans elles
     * la reponse est non, jamais « peut-etre ».
     */
    private function sameImage(ExternalPost $a, ExternalPost $b): bool
    {
        if (! $a->media_hash || ! $b->media_hash) {
            return false;
        }

        $distance = $this->hasher->distance($a->media_hash, $b->media_hash);

        return $distance !== null && $distance <= self::DHASH_TOLERANCE;
    }

    private function sameMediaShape(ExternalPost $a, ExternalPost $b): bool
    {
        $left = count($a->mediaItems());

        return $left > 0 && $left === count($b->mediaItems());
    }

    /**
     * @param  Collection<int, ExternalPost>  $members
     */
    private function keyFor(Collection $members): string
    {
        $parts = $members
            ->map(fn (ExternalPost $p) => $p->platform_id.':'.$p->external_id)
            ->sort()
            ->implode('|');

        return substr(sha1($parts), 0, 32);
    }
}
