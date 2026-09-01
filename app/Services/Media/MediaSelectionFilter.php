<?php

namespace App\Services\Media;

use App\Models\MediaFolder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vocabulaire commun pour désigner un lot de photos.
 *
 * Partagé par `GET /api/media/search` et par les routes de (dé)taguage en masse
 * `POST /api/partners/{partner}/media/{attach,detach}`. Une seule implémentation,
 * sinon les deux façons de désigner le même lot finiraient par diverger.
 *
 * Ne couvre QUE les filtres de sélection géographiques / temporels / d'arborescence.
 * Le tri, les embeddings, les filtres d'usage et l'intimité restent propres à la
 * recherche : ils répondent à des besoins de composition, pas de désignation.
 */
class MediaSelectionFilter
{
    /**
     * Règles de validation, à fusionner dans le validate() de l'appelant.
     *
     * @param  bool  $folderRequired  true pour la recherche (un dossier est
     *                                toujours le point de départ), false pour le
     *                                (dé)taguage, où l'on peut viser une ville seule.
     * @return array<string,string>
     */
    public static function rules(bool $folderRequired = false): array
    {
        return [
            'folder' => ($folderRequired ? 'required' : 'nullable').'|string|exists:media_folders,slug',
            'country' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'event' => 'nullable|string|max:200',
            'taken_at_from' => 'nullable|date',
            'taken_at_to' => 'nullable|date|after_or_equal:taken_at_from',
        ];
    }

    /**
     * Applique les filtres présents dans $params à la requête.
     *
     * Le dossier est traité par l'appelant (il décide quoi faire d'un dossier
     * privé : 403 global pour la recherche, saut comptabilisé pour le détaguage).
     *
     * @param  array<string,mixed>  $params
     */
    public static function apply(Builder $query, array $params): Builder
    {
        // Géographie : match exact case-insensitive sur les colonnes dédiées.
        // Distinct des tags, qui sont sémantiques et souvent incomplets.
        foreach (['country', 'city', 'region'] as $field) {
            if (! empty($params[$field])) {
                $query->whereRaw("LOWER({$field}) = ?", [mb_strtolower(trim($params[$field]))]);
            }
        }

        // Événement : même sémantique de match exact que la géographie.
        if (! empty($params['event'])) {
            $query->whereRaw('LOWER(event) = ?', [mb_strtolower(trim($params['event']))]);
        }

        // Fenêtre de prise de vue. Bornes incluses ; une photo sans taken_at
        // n'est jamais retenue dès qu'une borne est posée.
        if (! empty($params['taken_at_from'])) {
            $query->where('taken_at', '>=', $params['taken_at_from']);
        }
        if (! empty($params['taken_at_to'])) {
            $query->where('taken_at', '<=', $params['taken_at_to']);
        }

        return $query;
    }

    /**
     * Ids du dossier et de tous ses descendants PUBLICS.
     *
     * Un sous-dossier privé sous un parent public reste cloisonné : la descente
     * s'arrête à lui. Renvoie [] si la racine demandée est elle-même privée.
     *
     * @return array<int,int>
     */
    public static function publicDescendantIds(MediaFolder $root): array
    {
        if ($root->is_private) {
            return [];
        }

        $ids = [$root->id];
        $stack = [$root->id];

        while ($stack) {
            $children = MediaFolder::whereIn('parent_id', $stack)
                ->where('is_private', false)
                ->pluck('id')->all();
            if (! $children) {
                break;
            }
            $ids = array_merge($ids, $children);
            $stack = $children;
        }

        return $ids;
    }
}
