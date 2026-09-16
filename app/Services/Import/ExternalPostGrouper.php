<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use Illuminate\Support\Collection;
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
    /**
     * Deux reseaux ne recoivent jamais la publication a la seconde pres :
     * publication manuelle en serie, file d'attente, decalage d'horloge.
     */
    public const WINDOW_MINUTES = 30;

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

        if ($a->published_at->diffInMinutes($b->published_at, absolute: true) > self::WINDOW_MINUTES) {
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
