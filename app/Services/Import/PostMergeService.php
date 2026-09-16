<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\PartnerTagService;
use Illuminate\Support\Facades\DB;

/**
 * Joint deux publications RS-Max qui n'en sont qu'une.
 *
 * L'adoption regroupe les jumelles AVANT de creer la publication. Ce service
 * traite ce qui lui a echappe : les publications deja creees separement, parce
 * que le rapprochement par l'image n'existait pas encore, ou parce que le
 * reseau qui republie l'a fait apres coup.
 *
 * Absorber, ce n'est pas supprimer puis recreer : la publication conservee
 * garde son identite, donc ses tags partenaires, son id dans les comptes
 * rendus et ses liens. Seules les DIFFUSIONS changent de proprietaire.
 */
class PostMergeService
{
    public function __construct(private readonly PartnerTagService $partnerTags) {}

    /**
     * @return array{moved: int, partners: int}
     *
     * @throws \InvalidArgumentException si la fusion n'a pas de sens
     */
    public function merge(Post $keep, Post $absorbed): array
    {
        if ($keep->id === $absorbed->id) {
            throw new \InvalidArgumentException('Une publication ne se fusionne pas avec elle-meme.');
        }

        $keptPlatforms = $keep->postPlatforms()->pluck('platform_id')->all();
        $incoming = $absorbed->postPlatforms()->get();

        // Une publication ne porte qu'une diffusion par reseau : le composer
        // stocke un texte par reseau, pas deux.
        foreach ($incoming as $platform) {
            if (in_array($platform->platform_id, $keptPlatforms, true)) {
                throw new \InvalidArgumentException(
                    "Les deux publications ont une diffusion sur le meme reseau (#{$platform->platform_id})."
                );
            }
        }

        return DB::transaction(function () use ($keep, $absorbed, $incoming) {
            foreach ($incoming as $platform) {
                $platform->update(['post_id' => $keep->id]);
            }

            // Les publications natives pointent vers la publication absorbee :
            // sans ce report, elles ressortiraient comme adoptables.
            ExternalPost::where('adopted_post_id', $absorbed->id)
                ->update(['adopted_post_id' => $keep->id]);

            // Les textes par reseau de l'absorbee rejoignent la conservee.
            $contents = array_merge(
                $absorbed->platform_contents ?? [],
                $keep->platform_contents ?? []
            );

            $keep->update([
                'platform_contents' => $contents ?: null,
                // La vraie date de publication est la plus ancienne des deux :
                // le reseau qui republie le fait forcement apres.
                'published_at' => $keep->published_at && $absorbed->published_at
                    ? min($keep->published_at, $absorbed->published_at)
                    : ($keep->published_at ?? $absorbed->published_at),
                'media' => $keep->media ?: $absorbed->media,
            ]);

            // Les partenaires poses a la main sur l'absorbee ne doivent pas
            // disparaitre avec elle.
            $manual = $absorbed->partners()
                ->wherePivotIn('source', ['manual', PartnerTagService::SOURCE_TEXT])
                ->pluck('partners.id')
                ->merge($keep->partners()->wherePivotIn('source', ['manual', PartnerTagService::SOURCE_TEXT])->pluck('partners.id'))
                ->unique()
                ->values();

            $absorbed->partners()->detach();
            $absorbed->delete();

            $this->partnerTags->syncPost($keep->fresh(), $manual->all());

            return [
                'moved' => $incoming->count(),
                'partners' => $manual->count(),
            ];
        });
    }

    /**
     * La publication a conserver entre deux jumelles : la plus ancienne, celle
     * qui a ete publiee en premier. Le reseau qui republie vient toujours
     * apres, et c'est l'originale qui porte le vrai contexte.
     */
    public function pick(Post $a, Post $b): array
    {
        $aDate = $a->published_at;
        $bDate = $b->published_at;

        if ($aDate && $bDate && $bDate->lt($aDate)) {
            return [$b, $a];
        }

        return [$a, $b];
    }

    /**
     * Diffusion d'une publication, pour afficher un plan de fusion lisible.
     */
    public function describe(Post $post): string
    {
        return $post->postPlatforms
            ->map(fn (PostPlatform $p) => $p->platform?->slug ?? '?')
            ->implode(', ');
    }
}
