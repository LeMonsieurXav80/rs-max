<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Publication en masse V2 — « bibliothèque ».
 *
 * Même tableur que {@see BulkPostController} (les lignes sont persistées via
 * `posts.bulk.saveRow` / `posts.bulk.deleteRow`), mais les médias sont tirés
 * automatiquement dans les dossiers cochés de la médiathèque au lieu d'être
 * uploadés à la main.
 *
 * Garde-fous :
 *  - route WEB authentifiée à usage interne : les médias intimes/privés SONT
 *    tirables ici. La protection « public only » vit sur l'API publique à token
 *    ({@see \App\Http\Controllers\Api\MediaApiController}), pas sur cet outil ;
 *  - les médias marqués `never_publish` ne sont jamais tirés ;
 *  - une image déjà référencée par un post `scheduled|publishing|published`
 *    n'est jamais re-tirée.
 */
class BulkLibraryController extends Controller
{
    /** Niveau d'intimité explicitement exclu de toute publication. */
    private const NEVER_PUBLISH = 'never_publish';

    /** Statuts d'un post qui « consomment » définitivement une image. */
    private const USED_STATUSES = ['scheduled', 'publishing', 'published'];

    /**
     * Affiche l'assistant : sélection de comptes + arbre de dossiers publics à cocher.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get();

        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();
        $defaultAccountIds = $user->default_accounts ?? [];

        $folders = $this->buildFolderTree();

        return view('posts.bulk-library', compact('accounts', 'accountGroups', 'defaultAccountIds', 'folders'));
    }

    /**
     * Tire au hasard des images éligibles dans les dossiers cochés et renvoie
     * des lignes prêtes pour le tableur.
     */
    public function pick(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_ids' => 'required|array|min:1',
            'folder_ids.*' => 'integer|exists:media_folders,id',
            'num_posts' => 'required|integer|min:1|max:100',
            'images_per_post' => 'required|integer|min:1|max:10',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:100',
        ]);

        $numPosts = (int) $validated['num_posts'];
        $imagesPerPost = (int) $validated['images_per_post'];
        $folderIds = $validated['folder_ids'];

        // Mots-clés normalisés (trim + non vides). Une image est retenue si elle
        // matche AU MOINS UN mot-clé (OR).
        $keywords = array_values(array_filter(
            array_map(fn ($k) => trim((string) $k), $validated['keywords'] ?? []),
            fn ($k) => $k !== ''
        ));

        // Ensemble des filenames déjà consommés (planifiés OU publiés).
        $usedFilenames = $this->usedFilenames();

        // Images éligibles : dans les dossiers cochés, type image, non « never_publish »,
        // pas déjà planifiées/publiées, et — si des mots-clés sont fournis — matchant au
        // moins un mot-clé sur tags / description / lieu (ville, région, pays).
        // LIKE sur le texte brut de thematic_tags (JSON) : portable SQLite (dev) + MySQL (prod),
        // là où JSON_SEARCH ne l'est pas.
        $eligible = MediaFile::query()
            ->whereIn('folder_id', $folderIds)
            ->tap(fn ($q) => $this->scopePublishableIntimacy($q))
            ->where('mime_type', 'like', 'image/%')
            ->when(! empty($usedFilenames), fn ($q) => $q->whereNotIn('filename', $usedFilenames))
            ->when(! empty($keywords), fn ($q) => $q->where(function ($outer) use ($keywords) {
                foreach ($keywords as $kw) {
                    $like = '%'.strtolower($kw).'%';
                    $outer->orWhere(function ($w) use ($like) {
                        $w->whereRaw('LOWER(thematic_tags) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(description_fr) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(region) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(country) LIKE ?', [$like]);
                    });
                }
            }))
            ->get(['id', 'filename', 'mime_type', 'folder_id']);

        // Tirage round-robin entre dossiers pour maximiser la variété.
        $needed = $numPosts * $imagesPerPost;
        $picked = $this->pickRoundRobin($eligible, $needed);

        // Découpe en lignes de `imagesPerPost` images ; on abandonne une éventuelle
        // dernière ligne incomplète (mieux vaut 3 posts complets que 4 bancals).
        $rows = [];
        foreach (array_chunk($picked, $imagesPerPost) as $chunk) {
            if (count($chunk) < $imagesPerPost) {
                break;
            }
            $rows[] = array_map(fn (MediaFile $m) => [
                'url' => $m->url,
                'filename' => $m->filename,
                'mimetype' => $m->mime_type,
                'title' => $m->filename,
            ], $chunk);
        }

        return response()->json([
            'rows' => $rows,
            'requested' => $numPosts,
            'available' => count($rows),
            'shortfall' => count($rows) < $numPosts,
        ]);
    }

    /**
     * Restreint une requête aux médias publiables : tout sauf `never_publish`.
     * `orWhereNull` est indispensable car en SQL `intimacy_level != 'x'` écarte
     * silencieusement les lignes à NULL (un média non classé serait alors invisible).
     */
    private function scopePublishableIntimacy($query): void
    {
        $query->where(function ($q) {
            $q->where('intimacy_level', '!=', self::NEVER_PUBLISH)
                ->orWhereNull('intimacy_level');
        });
    }

    /**
     * Filenames de toutes les images déjà référencées par un post consommateur
     * (scheduled|publishing|published). Basé sur le JSON `posts.media`, car les
     * posts planifiés non publiés n'existent pas encore dans media_publications.
     *
     * @return array<int,string>
     */
    private function usedFilenames(): array
    {
        $filenames = [];

        Post::whereIn('status', self::USED_STATUSES)
            ->whereNotNull('media')
            ->select('media')
            ->chunk(500, function ($posts) use (&$filenames) {
                foreach ($posts as $post) {
                    foreach ((array) $post->media as $item) {
                        $url = is_array($item) ? ($item['url'] ?? null) : $item;
                        if (is_string($url) && $url !== '') {
                            $filenames[basename($url)] = true;
                        }
                    }
                }
            });

        return array_keys($filenames);
    }

    /**
     * Tire `$needed` médias en alternant entre dossiers (round-robin), chaque
     * dossier étant mélangé aléatoirement au préalable.
     *
     * @param  \Illuminate\Support\Collection<int,MediaFile>  $eligible
     * @return array<int,MediaFile>
     */
    private function pickRoundRobin($eligible, int $needed): array
    {
        // Groupe par dossier, mélange chaque groupe.
        $groups = $eligible->groupBy('folder_id')
            ->map(fn ($g) => $g->shuffle()->values())
            ->values();

        // Mélange aussi l'ordre des dossiers pour ne pas toujours démarrer par le même.
        $groups = $groups->shuffle()->values();

        $picked = [];
        $exhausted = false;
        while (count($picked) < $needed && ! $exhausted) {
            $exhausted = true;
            foreach ($groups as $group) {
                if ($group->isNotEmpty()) {
                    $picked[] = $group->shift();
                    $exhausted = false;
                    if (count($picked) >= $needed) {
                        break;
                    }
                }
            }
        }

        return $picked;
    }

    /**
     * Construit l'arbre COMPLET des dossiers (à plat, avec depth/path), y compris
     * les dossiers privés/intimes — cet outil est interne. Le flag `is_private`
     * permet à l'UI d'afficher un cadenas. `files_count` = images publiables du
     * dossier (tout sauf `never_publish`), non récursif.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildFolderTree(): array
    {
        $all = MediaFolder::orderBy('sort_order')->orderBy('name')->get();

        // Compte des images publiables par dossier (direct, non récursif).
        $imageCounts = MediaFile::query()
            ->tap(fn ($q) => $this->scopePublishableIntimacy($q))
            ->where('mime_type', 'like', 'image/%')
            ->selectRaw('folder_id, count(*) as c')
            ->groupBy('folder_id')
            ->pluck('c', 'folder_id');

        $byParent = $all->groupBy('parent_id');

        // Compte RÉCURSIF (dossier + tous ses descendants), mémoïsé.
        $recursive = [];
        $computeRec = function ($id) use (&$computeRec, &$recursive, $byParent, $imageCounts) {
            if (array_key_exists($id, $recursive)) {
                return $recursive[$id];
            }
            $total = (int) ($imageCounts[$id] ?? 0);
            foreach ($byParent->get($id, collect()) as $child) {
                $total += $computeRec($child->id);
            }

            return $recursive[$id] = $total;
        };

        $tree = [];
        $walk = function ($parentId, int $depth, string $prefix) use (&$walk, &$tree, $byParent, $imageCounts, $computeRec) {
            foreach ($byParent->get($parentId, collect()) as $folder) {
                $path = $prefix === '' ? $folder->name : $prefix.' / '.$folder->name;
                $tree[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'depth' => $depth,
                    'path' => $path,
                    'color' => $folder->color,
                    'is_private' => (bool) $folder->is_private,
                    'files_count' => (int) ($imageCounts[$folder->id] ?? 0),
                    'files_count_total' => $computeRec($folder->id),
                ];
                $walk($folder->id, $depth + 1, $path);
            }
        };
        $walk(null, 0, '');

        return $tree;
    }
}
