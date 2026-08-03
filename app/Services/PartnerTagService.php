<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\Partner;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Support\Collection;

/**
 * Point d'entree unique du tag partenaire : resolution des noms en fiches,
 * synchronisation des photos et report automatique sur les publications.
 *
 * Regle du report : a chaque enregistrement d'un post, les partenaires 'auto'
 * sont recalcules depuis les photos actuellement attachees ; ceux poses a la
 * main ('manual') sont preserves et prennent le pas en cas de doublon.
 */
class PartnerTagService
{
    /**
     * Resout une liste de noms libres en fiches partenaires (creation a la volee).
     * Dedup par slug : « Coca-Cola », « coca cola » et « COCA COLA » convergent.
     *
     * @param  array<int,string>  $names
     * @return Collection<int,Partner> indexee par slug, dans l'ordre d'entree
     */
    public function resolveNames(array $names, string $origin = 'manual'): Collection
    {
        $wanted = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $clean = trim($name);
            if ($clean === '' || mb_strlen($clean) > 80) {
                continue;
            }
            $slug = Partner::slugFor($clean);
            if ($slug !== '' && ! isset($wanted[$slug])) {
                $wanted[$slug] = $clean;
            }
        }

        if (empty($wanted)) {
            return collect();
        }

        $existing = Partner::whereIn('slug', array_keys($wanted))->get()->keyBy('slug');

        $resolved = collect();
        foreach ($wanted as $slug => $name) {
            $resolved[$slug] = $existing->get($slug) ?? Partner::create([
                'name' => $name,
                'slug' => $slug,
                'origin' => $origin,
            ]);
        }

        return $resolved;
    }

    /**
     * Remplace les partenaires d'une photo a partir de noms libres.
     * Met a jour le pivot ET le miroir denormalise media_files.brands.
     *
     * @param  array<int,string>  $names
     * @return array<int,string> noms canoniques retenus
     */
    public function syncMediaNames(MediaFile $media, array $names, string $origin = 'manual'): array
    {
        $partners = $this->resolveNames($names, $origin);

        $media->partners()->sync($partners->pluck('id')->all());

        $canonical = $partners->pluck('name')->values()->all();
        $media->update(['brands' => $canonical]);

        return $canonical;
    }

    /**
     * Ajoute/retire des marques sur une photo sans toucher au reste (edition en masse).
     *
     * @param  array<int,string>  $add
     * @param  array<int,string>  $remove
     * @return array<int,string> noms canoniques apres operation
     */
    public function amendMediaNames(MediaFile $media, array $add, array $remove, string $origin = 'manual'): array
    {
        $removeSlugs = [];
        foreach ($remove as $name) {
            $slug = Partner::slugFor((string) $name);
            if ($slug !== '') {
                $removeSlugs[$slug] = true;
            }
        }

        $current = $media->relationLoaded('partners') ? $media->partners : $media->partners()->get();

        $names = $current
            ->reject(fn (Partner $p) => isset($removeSlugs[$p->slug]))
            ->pluck('name')
            ->all();

        return $this->syncMediaNames($media, array_merge($names, $add), $origin);
    }

    /**
     * Partenaires deduits des photos attachees a une publication.
     * Les items media portent parfois l'id du MediaFile, sinon on retombe sur
     * le nom de fichier extrait de l'URL (format historique de posts.media).
     *
     * @return array<int,int> ids de partenaires
     */
    public function partnerIdsFromMedia(?array $media): array
    {
        if (empty($media)) {
            return [];
        }

        $ids = [];
        $filenames = [];

        foreach ($media as $item) {
            if (is_array($item) && ! empty($item['id']) && is_numeric($item['id'])) {
                $ids[] = (int) $item['id'];

                continue;
            }

            $url = is_string($item) ? $item : ($item['url'] ?? '');
            $filename = basename((string) parse_url((string) $url, PHP_URL_PATH));
            if ($filename !== '') {
                $filenames[] = $filename;
            }
        }

        if (empty($ids) && empty($filenames)) {
            return [];
        }

        return MediaFile::query()
            ->where(function ($q) use ($ids, $filenames) {
                if ($ids) {
                    $q->orWhereIn('id', $ids);
                }
                if ($filenames) {
                    $q->orWhereIn('filename', $filenames);
                }
            })
            ->with('partners:id')
            ->get()
            ->flatMap(fn (MediaFile $mf) => $mf->partners->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Partenaires par URL de media, pour l'apercu « tags herites » du formulaire
     * de post (les items de posts.media anciens ne portent pas cette info).
     *
     * @return array<string,array<int,array{id:int,name:string,color:string}>>
     */
    public function partnersByMediaUrl(?array $media): array
    {
        if (empty($media)) {
            return [];
        }

        $urlByFilename = [];
        foreach ($media as $item) {
            $url = is_string($item) ? $item : ($item['url'] ?? '');
            $filename = basename((string) parse_url((string) $url, PHP_URL_PATH));
            if ($filename !== '') {
                $urlByFilename[$filename] = $url;
            }
        }

        if (empty($urlByFilename)) {
            return [];
        }

        $map = [];
        MediaFile::whereIn('filename', array_keys($urlByFilename))
            ->with('partners:id,name,color')
            ->get(['id', 'filename'])
            ->each(function (MediaFile $mf) use ($urlByFilename, &$map) {
                $map[$urlByFilename[$mf->filename]] = $mf->partners
                    ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color])
                    ->values()
                    ->all();
            });

        return $map;
    }

    /**
     * Recalcule les tags d'une publication.
     *
     * Fonctionne quel que soit le statut : un post deja publie reste taguable,
     * puisque le tag est une metadonnee interne et non du contenu.
     *
     * @param  array<int,int>|null  $manualIds  ids poses a la main ; null = conserver ceux deja en base
     */
    public function syncPost(Post $post, ?array $manualIds = null): void
    {
        $this->syncTaggable($post, $this->partnerIdsFromMedia($post->media), $manualIds);
    }

    /**
     * Idem pour un fil : les tags 'auto' viennent des photos de TOUS ses segments.
     *
     * @param  array<int,int>|null  $manualIds
     */
    public function syncThread(Thread $thread, ?array $manualIds = null): void
    {
        $media = $thread->segments()->pluck('media')
            ->filter(fn ($m) => is_array($m))
            ->flatten(1)
            ->all();

        $this->syncTaggable($thread, $this->partnerIdsFromMedia($media), $manualIds);
    }

    /**
     * Coeur commun post/fil : les 'auto' sont remplaces, les 'manual' priment.
     *
     * @param  array<int,int>  $autoIds
     * @param  array<int,int>|null  $manualIds
     */
    private function syncTaggable(Post|Thread $model, array $autoIds, ?array $manualIds): void
    {
        $manual = $manualIds === null
            ? $model->partners()->wherePivot('source', 'manual')->pluck('partners.id')->all()
            : array_map('intval', $manualIds);

        // On revalide toujours contre la base : un id supprime ou invente ne doit rien casser.
        $manual = empty($manual) ? [] : Partner::whereIn('id', $manual)->pluck('id')->all();

        $sync = [];
        foreach ($autoIds as $id) {
            $sync[$id] = ['source' => 'auto'];
        }
        foreach ($manual as $id) {
            $sync[$id] = ['source' => 'manual'];
        }

        $model->partners()->sync($sync);
        $model->unsetRelation('partners');
    }

    /**
     * Liste des partenaires actifs pour les selecteurs (formulaire post, media).
     *
     * @return array<int,array{id:int,name:string,color:string}>
     */
    public function options(): array
    {
        return Partner::active()
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color])
            ->all();
    }
}
