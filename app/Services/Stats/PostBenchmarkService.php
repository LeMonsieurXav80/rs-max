<?php

namespace App\Services\Stats;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

/**
 * Compare un brouillon à l'historique publié DU MÊME COMPTE.
 *
 * Le ranker de X ne note pas un post dans l'absolu : son score est une propriété
 * du couple (post, lecteur) — deux profils de lecteurs différents n'ont eu aucun
 * post en commun dans leurs 30 premiers résultats lors de nos mesures. Comparer
 * un brouillon à la moyenne « des bons posts » n'a donc aucun sens.
 *
 * En revanche, sur un compte donné, l'audience est à peu près constante d'un post
 * au suivant : le côté lecteur est tenu fixe, et ce qui reste s'explique par le
 * contenu, le format et l'horaire. C'est la seule comparaison légitime.
 *
 * On ne produit volontairement PAS un score : sur quelques centaines de posts,
 * une régression apprendrait surtout du bruit. On sort des médianes par cohorte,
 * lisibles et vérifiables, et on se tait quand l'échantillon est trop maigre.
 */
class PostBenchmarkService
{
    /** Posts nécessaires sur le compte avant de dire quoi que ce soit. */
    public const MIN_SAMPLE = 20;

    /** Posts nécessaires de CHAQUE côté d'une comparaison. */
    public const MIN_GROUP = 8;

    /** Seuil de longueur séparant les posts courts des longs. */
    private const LONG_THRESHOLD = 150;

    /**
     * @param  array{length?:int, has_media?:bool, has_link?:bool, hour?:int|null}  $draft
     */
    public function forDraft(SocialAccount $account, array $draft): array
    {
        $history = $this->history($account);

        if ($history->count() < self::MIN_SAMPLE) {
            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'sample' => $history->count(),
                'min_sample' => self::MIN_SAMPLE,
                'insufficient' => true,
                'median' => null,
                'signals' => [],
            ];
        }

        $median = $this->median($history->pluck('value')->all());

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'sample' => $history->count(),
            'min_sample' => self::MIN_SAMPLE,
            'insufficient' => false,
            'median' => $median,
            'signals' => $this->signals($history, $draft),
        ];
    }

    /**
     * Publications du compte avec métriques exploitables, réduites aux
     * caractéristiques comparables.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function history(SocialAccount $account): Collection
    {
        return PostPlatform::with('post')
            ->where('social_account_id', $account->id)
            ->where('status', 'published')
            ->whereNotNull('metrics')
            ->whereNotNull('published_at')
            ->get()
            ->map(function (PostPlatform $pp) {
                $metrics = $pp->metrics ?? [];

                // On suit les vues ; à défaut les likes, pour les plateformes qui n'en donnent pas.
                $value = $metrics['views'] ?? $metrics['likes'] ?? null;

                if ($value === null || ! $pp->post) {
                    return null;
                }

                $content = (string) ($pp->post->content_fr ?? '');

                return [
                    'value' => (int) $value,
                    'length' => mb_strlen($content),
                    'has_media' => ! empty($pp->post->media),
                    'has_link' => ! empty($pp->post->link_url),
                    'hour' => (int) $pp->published_at->format('G'),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Une comparaison par caractéristique du brouillon, chacune muette si l'un
     * des deux groupes est trop petit pour être crédible.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    private function signals(Collection $history, array $draft): array
    {
        $signals = [];

        if (isset($draft['has_media'])) {
            $signals[] = $this->compare(
                $history,
                fn ($p) => $p['has_media'] === (bool) $draft['has_media'],
                $draft['has_media'] ? 'Avec média' : 'Sans média',
                'media',
            );
        }

        if (isset($draft['has_link'])) {
            $signals[] = $this->compare(
                $history,
                fn ($p) => $p['has_link'] === (bool) $draft['has_link'],
                $draft['has_link'] ? 'Avec lien' : 'Sans lien',
                'link',
            );
        }

        if (isset($draft['length'])) {
            $isLong = $draft['length'] >= self::LONG_THRESHOLD;
            $signals[] = $this->compare(
                $history,
                fn ($p) => ($p['length'] >= self::LONG_THRESHOLD) === $isLong,
                $isLong ? 'Texte long' : 'Texte court',
                'length',
            );
        }

        if (isset($draft['hour']) && $draft['hour'] !== null) {
            $slot = $this->slot((int) $draft['hour']);
            $signals[] = $this->compare(
                $history,
                fn ($p) => $this->slot($p['hour']) === $slot,
                'Publié '.$slot,
                'hour',
            );
        }

        return array_values(array_filter($signals));
    }

    /**
     * Médiane du groupe correspondant au brouillon contre celle du reste.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     */
    private function compare(Collection $history, callable $matches, string $label, string $key): ?array
    {
        $group = $history->filter($matches);
        $others = $history->reject($matches);

        // Sans les deux côtés, la comparaison ne veut rien dire.
        if ($group->count() < self::MIN_GROUP || $others->count() < self::MIN_GROUP) {
            return null;
        }

        $groupMedian = $this->median($group->pluck('value')->all());
        $otherMedian = $this->median($others->pluck('value')->all());

        return [
            'key' => $key,
            'label' => $label,
            'median' => $groupMedian,
            'other_median' => $otherMedian,
            'n' => $group->count(),
            'n_other' => $others->count(),
            'ratio' => $otherMedian > 0 ? round($groupMedian / $otherMedian, 2) : null,
        ];
    }

    private function slot(int $hour): string
    {
        return match (true) {
            $hour < 6 => 'la nuit',
            $hour < 12 => 'le matin',
            $hour < 18 => "l'après-midi",
            default => 'le soir',
        };
    }

    /**
     * @param  array<int, int>  $values
     */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
