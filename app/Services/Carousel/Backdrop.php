<?php

namespace App\Services\Carousel;

/**
 * Continuité d'image entre slides voisines.
 *
 * Une photo (surtout en paysage) peut se prolonger sur la slide suivante : au
 * swipe, on parcourt une seule image au lieu d'en changer. C'est l'effet
 * « panorama » des carrousels immersifs.
 *
 * Le rendu final rasterise UNE SLIDE À LA FOIS (la bande ne sert qu'à l'aperçu) :
 * la continuité ne peut donc pas être posée sur la bande. Chaque slide du groupe
 * affiche l'image ENTIÈRE dans un cadre large de `span × w`, décalé vers la
 * gauche de sa position dans le groupe, et son `overflow:hidden` en découpe la
 * bonne portion. Même mécanique dans l'aperçu et à l'export — donc ce qu'on voit
 * est ce qui sort.
 *
 * Les clés `_span` / `_span_index` sont posées par CarouselRenderService::linkSpans().
 * Le préfixe `_` marque une donnée calculée : elle n'est pas saisissable et ne
 * fait pas partie du contrat public des slots.
 */
final class Backdrop
{
    /**
     * Style CSS du cadre qui porte l'image de fond d'une slide.
     *
     * Sans continuité : le cadre épouse la slide (`inset:0`), soit le rendu
     * historique. Avec : il couvre tout le groupe et se décale, si bien que la
     * slide n'en montre que sa part.
     *
     * @param  array<string, mixed>  $data
     */
    public static function frame(array $data, int $w, int $h): string
    {
        $span = max(1, (int) ($data['_span'] ?? 1));

        if ($span === 1) {
            return 'position:absolute; inset:0;';
        }

        $index = max(0, min($span - 1, (int) ($data['_span_index'] ?? 0)));

        return sprintf(
            'position:absolute; top:0; left:%dpx; width:%dpx; height:%dpx;',
            -$index * $w,
            $span * $w,
            $h,
        );
    }

    /** La slide fait-elle partie d'un groupe d'images continues ? */
    public static function spans(array $data): bool
    {
        return (int) ($data['_span'] ?? 1) > 1;
    }
}
