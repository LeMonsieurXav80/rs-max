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
 * Garde-fous imposés CÔTÉ SERVEUR (indépendamment de l'UI) :
 *  - seuls les médias `intimacy_level = public` sont tirables ;
 *  - les dossiers effectivement privés (privés ou sous un ancêtre privé) sont
 *    exclus, même si leur id est passé explicitement à `pick()` ;
 *  - une image déjà référencée par un post `scheduled|publishing|published`
 *    n'est jamais re-tirée.
 */
class BulkLibraryController extends Controller
{
    /** Seul niveau d'intimité tirable en masse (aligné sur MediaApiController::SAFE_INTIMACY). */
    private const SAFE_INTIMACY = ['public'];

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
        ]);

        $numPosts = (int) $validated['num_posts'];
        $imagesPerPost = (int) $validated['images_per_post'];

        // Garde-fou : ne garder que les dossiers effectivement PUBLICS, quelle que
        // soit la liste reçue (protège contre un id de dossier privé forgé en API).
        $folders = MediaFolder::whereIn('id', $validated['folder_ids'])->get();
        $publicFolderIds = $folders
            ->reject(fn (MediaFolder $f) => $f->isEffectivelyPrivate())
            ->pluck('id')
            ->all();

        if (empty($publicFolderIds)) {
            return response()->json([
                'rows' => [],
                'requested' => $numPosts,
                'available' => 0,
                'shortfall' => true,
                'message' => 'Aucun dossier public sélectionné.',
            ]);
        }

        // Ensemble des filenames déjà consommés (planifiés OU publiés).
        $usedFilenames = $this->usedFilenames();

        // Images éligibles : public + type image + pas déjà utilisées.
        $eligible = MediaFile::query()
            ->whereIn('folder_id', $publicFolderIds)
            ->whereIn('intimacy_level', self::SAFE_INTIMACY)
            ->where('mime_type', 'like', 'image/%')
            ->when(! empty($usedFilenames), fn ($q) => $q->whereNotIn('filename', $usedFilenames))
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
     * Construit l'arbre des dossiers PUBLICS (à plat, avec depth/path), avec le
     * nombre d'images publiques dans chaque dossier. Les dossiers privés (ou sous
     * un ancêtre privé) sont totalement omis.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildFolderTree(): array
    {
        $all = MediaFolder::orderBy('sort_order')->orderBy('name')->get();

        // Compte des images publiques par dossier (non récursif).
        $imageCounts = MediaFile::whereIn('intimacy_level', self::SAFE_INTIMACY)
            ->where('mime_type', 'like', 'image/%')
            ->selectRaw('folder_id, count(*) as c')
            ->groupBy('folder_id')
            ->pluck('c', 'folder_id');

        $byParent = $all->groupBy('parent_id');

        $tree = [];
        $walk = function ($parentId, int $depth, string $prefix) use (&$walk, &$tree, $byParent, $imageCounts) {
            foreach ($byParent->get($parentId, collect()) as $folder) {
                if ($folder->is_private) {
                    continue; // coupe toute la branche privée
                }
                $path = $prefix === '' ? $folder->name : $prefix.' / '.$folder->name;
                $tree[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'depth' => $depth,
                    'path' => $path,
                    'color' => $folder->color,
                    'files_count' => (int) ($imageCounts[$folder->id] ?? 0),
                ];
                $walk($folder->id, $depth + 1, $path);
            }
        };
        $walk(null, 0, '');

        return $tree;
    }
}
