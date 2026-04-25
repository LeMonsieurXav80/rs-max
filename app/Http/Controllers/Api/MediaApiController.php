<?php

namespace App\Http\Controllers\Api;

use App\Concerns\ProcessesImages;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaPublication;
use App\Services\StockPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaApiController extends Controller
{
    use ProcessesImages;

    // Tous les pools existants (acceptés par /ingest, /validate, /classify, /enrich).
    private const ALL_POOLS = ['wildycaro', 'pdc_vantour', 'mamawette'];

    // Pools accessibles via /api/media/search. Mamawette est exclu pour cloisonnement strict :
    // les photos mamawette sont uniquement publiables manuellement via la médiathèque web.
    private const SEARCHABLE_POOLS = ['wildycaro', 'pdc_vantour'];

    private const INTIMACY_LEVELS = ['public', 'prive', 'never_publish'];

    // Niveaux d'intimacy retournables par /search. `prive` ne sort jamais via cet endpoint
    // (les photos prive sont mamawette-only, et mamawette n'est pas searchable).
    private const SAFE_INTIMACY = ['public'];

    /**
     * POST /api/media/ingest — pipeline Mac.
     * Reçoit le fichier + métadonnées pré-calculées (description, embedding, tags, pool, etc.).
     * Idempotent par phash : si la photo existe déjà, renvoie l'ID existant.
     */
    public function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,gif,webp|max:51200',
            'metadata' => 'required|json',
        ]);

        $meta = json_decode($request->input('metadata'), true);
        if (! is_array($meta)) {
            return response()->json(['error' => 'metadata must be a JSON object'], 422);
        }

        $metaValidator = validator($meta, [
            'description_fr' => 'nullable|string',
            'thematic_tags' => 'nullable|array',
            'thematic_tags.*' => 'string',
            'embedding' => 'nullable|array',
            'embedding.*' => 'numeric',
            'embedding_model' => 'nullable|string|max:50',
            'pool_suggested' => ['nullable', Rule::in([...self::ALL_POOLS, 'both', 'none'])],
            'people_ids' => 'nullable|array',
            'people_ids.*' => 'string',
            'intimacy_level' => ['nullable', Rule::in(self::INTIMACY_LEVELS)],
            'allow_wildycaro' => 'nullable|boolean',
            'allow_pdc_vantour' => 'nullable|boolean',
            'allow_mamawette' => 'nullable|boolean',
            'ai_metadata' => 'nullable|array',
            'source_context' => 'nullable|string',
            'source_path' => 'nullable|string|max:512',
            'phash' => 'required|string|max:64',
            'folder_path' => 'nullable|string|max:255',
        ]);

        if ($metaValidator->fails()) {
            return response()->json(['error' => 'invalid metadata', 'details' => $metaValidator->errors()], 422);
        }
        $meta = $metaValidator->validated();

        // Idempotence par phash
        $existing = MediaFile::where('phash', $meta['phash'])->first();
        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'status' => 'exists',
                'filename' => $existing->filename,
                'url_full' => "/media/{$existing->filename}",
                'url_thumb' => $existing->is_video ? route('media.thumbnail', $existing->filename) : "/media/{$existing->filename}",
            ]);
        }

        $file = $request->file('file');
        $sourceMime = $file->getMimeType();

        // Génère un filename à la même convention que MediaController::upload
        $extension = $this->outputExtension($sourceMime, $file->getRealPath());
        $filename = date('Ymd_His').'_'.Str::random(8).'.'.$extension;

        // Compression GD identique à l'upload web (taille max, qualité adaptative).
        $processed = $this->processImage($file->getRealPath(), $sourceMime, $filename);
        if (! $processed['success']) {
            return response()->json(['error' => 'image processing failed', 'details' => $processed['error']], 422);
        }

        $filename = $processed['filename']; // peut changer (PNG sans transparence → JPG)

        $folderId = null;
        if (! empty($meta['folder_path'])) {
            $folder = MediaFolder::firstOrCreate(
                ['name' => $meta['folder_path']],
                ['slug' => Str::slug($meta['folder_path'])]
            );
            $folderId = $folder->id;
        }

        $mediaFile = MediaFile::create([
            'folder_id' => $folderId,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $processed['mimetype'],
            'size' => $processed['size'],
            'width' => $processed['width'],
            'height' => $processed['height'],
            'source' => 'mac_pipeline',
            'description_fr' => $meta['description_fr'] ?? null,
            'thematic_tags' => $this->normalizeTags($meta['thematic_tags'] ?? null),
            'embedding' => $meta['embedding'] ?? null,
            'embedding_model' => $meta['embedding_model'] ?? null,
            'pool_suggested' => $meta['pool_suggested'] ?? null,
            'allow_wildycaro' => (bool) ($meta['allow_wildycaro'] ?? false),
            'allow_pdc_vantour' => (bool) ($meta['allow_pdc_vantour'] ?? false),
            'allow_mamawette' => (bool) ($meta['allow_mamawette'] ?? false),
            'intimacy_level' => $meta['intimacy_level'] ?? 'public',
            'people_ids' => $meta['people_ids'] ?? null,
            'ai_metadata' => $meta['ai_metadata'] ?? null,
            'source_context' => $meta['source_context'] ?? null,
            'source_path' => $meta['source_path'] ?? null,
            'phash' => $meta['phash'],
            'pending_analysis' => false,
            'ingested_at' => now(),
        ]);

        return response()->json([
            'id' => $mediaFile->id,
            'status' => 'created',
            'filename' => $filename,
            'url_full' => "/media/{$filename}",
            'url_thumb' => "/media/{$filename}",
        ], 201);
    }

    /**
     * POST /api/media/{id}/validate — corrige les flags d'une photo après coup.
     */
    public function validateMedia(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'allow_wildycaro' => 'nullable|boolean',
            'allow_pdc_vantour' => 'nullable|boolean',
            'allow_mamawette' => 'nullable|boolean',
            'intimacy_level' => ['nullable', Rule::in(self::INTIMACY_LEVELS)],
            'thematic_tags_override' => 'nullable|array',
            'thematic_tags_override.*' => 'string',
            'people_ids_override' => 'nullable|array',
            'people_ids_override.*' => 'string',
        ]);

        $update = array_filter([
            'allow_wildycaro' => $data['allow_wildycaro'] ?? null,
            'allow_pdc_vantour' => $data['allow_pdc_vantour'] ?? null,
            'allow_mamawette' => $data['allow_mamawette'] ?? null,
            'intimacy_level' => $data['intimacy_level'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('thematic_tags_override', $data)) {
            $update['thematic_tags'] = $this->normalizeTags($data['thematic_tags_override']);
        }
        if (array_key_exists('people_ids_override', $data)) {
            $update['people_ids'] = $data['people_ids_override'];
        }

        if (empty($update)) {
            return response()->json(['error' => 'no fields to update'], 422);
        }

        $media->update($update);

        return response()->json([
            'id' => $media->id,
            'updated' => array_keys($update),
            'allow_wildycaro' => $media->allow_wildycaro,
            'allow_pdc_vantour' => $media->allow_pdc_vantour,
            'allow_mamawette' => $media->allow_mamawette,
            'intimacy_level' => $media->intimacy_level,
        ]);
    }

    /**
     * GET /api/media/search — recherche sémantique par embedding.
     * Pool obligatoire, double garde-fou intimacy_level.
     */
    public function search(Request $request): JsonResponse
    {
        $params = $request->validate([
            'pool' => ['required', Rule::in(self::SEARCHABLE_POOLS)],
            'query_embedding' => 'nullable|array|min:1',
            'query_embedding.*' => 'numeric',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'people' => 'nullable|array',
            'people.*' => 'string',
            'exclude_recently_published_days' => 'nullable|integer|min:0|max:3650',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $pool = $params['pool'];
        $limit = $params['limit'] ?? 20;
        $excludeDays = $params['exclude_recently_published_days']
            ?? ($pool === 'pdc_vantour' ? 30 : 0);

        $allowColumn = "allow_{$pool}";
        $hasEmbedding = ! empty($params['query_embedding']);

        $query = MediaFile::query()
            ->where($allowColumn, true)
            ->whereIn('intimacy_level', self::SAFE_INTIMACY);

        // L'embedding n'est requis que si on veut faire un tri sémantique.
        // En mode "match par mots-clés", il est facultatif.
        if ($hasEmbedding) {
            $query->whereNotNull('embedding');
        }

        // Recherche par tag : sous-chaîne tolérante via JSON_SEARCH (LIKE).
        // "chaise longue" trouvera "chaise longue bleue", "vanlife" → "vanlife espagne", etc.
        if (! empty($params['tags'])) {
            foreach ($params['tags'] as $tag) {
                $query->whereRaw(
                    "JSON_SEARCH(LOWER(thematic_tags), 'one', ?, NULL, '$[*]') IS NOT NULL",
                    ['%'.strtolower($tag).'%']
                );
            }
        }
        // Personnes : match exact (les ids sont normalisés "caroline", "xavier" — pas de variations).
        if (! empty($params['people'])) {
            foreach ($params['people'] as $person) {
                $query->whereJsonContains('people_ids', strtolower($person));
            }
        }
        if ($excludeDays > 0) {
            $cutoff = now()->subDays($excludeDays);
            $query->whereDoesntHave('publications', function ($q) use ($cutoff) {
                $q->where('published_at', '>=', $cutoff);
            });
        }

        $results = [];

        if ($hasEmbedding) {
            // Mode embedding : tri par similarité cosine décroissante.
            $candidates = $query->get(['id', 'filename', 'mime_type', 'description_fr', 'thematic_tags', 'people_ids', 'embedding']);
            $queryVec = array_map('floatval', $params['query_embedding']);
            foreach ($candidates as $row) {
                $emb = $row->embedding;
                if (! is_array($emb) || count($emb) !== count($queryVec)) {
                    continue;
                }
                $results[] = [
                    'id' => $row->id,
                    'similarity' => $this->cosineSimilarity($queryVec, $emb),
                    'url_thumb' => "/media/{$row->filename}",
                    'url_full' => "/media/{$row->filename}",
                    'description_fr' => $row->description_fr,
                    'thematic_tags' => $row->thematic_tags,
                    'people_ids' => $row->people_ids,
                ];
            }
            usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        } else {
            // Mode mots-clés : ordre random parmi les photos qui matchent les filtres.
            // Idéal pour "donne-moi une photo de bacalhau pour ce tweet" — variété assurée
            // entre plusieurs appels successifs sans avoir à passer un embedding.
            $candidates = $query->inRandomOrder()
                ->limit($limit)
                ->get(['id', 'filename', 'mime_type', 'description_fr', 'thematic_tags', 'people_ids']);
            foreach ($candidates as $row) {
                $results[] = [
                    'id' => $row->id,
                    'url_thumb' => "/media/{$row->filename}",
                    'url_full' => "/media/{$row->filename}",
                    'description_fr' => $row->description_fr,
                    'thematic_tags' => $row->thematic_tags,
                    'people_ids' => $row->people_ids,
                ];
            }
        }

        $results = array_slice($results, 0, $limit);

        Log::channel(config('logging.channels.media_search') ? 'media_search' : 'stack')
            ->info('media.search', [
                'token_name' => $request->user()?->currentAccessToken()?->name,
                'pool' => $pool,
                'tags' => $params['tags'] ?? [],
                'people' => $params['people'] ?? [],
                'exclude_days' => $excludeDays,
                'returned_ids' => array_column($results, 'id'),
                'candidates_count' => count($candidates),
            ]);

        return response()->json([
            'results' => $results,
            'filters_applied' => [
                'pool' => $pool,
                'intimacy' => implode('|', self::SAFE_INTIMACY),
                'exclude_recently_published_days' => $excludeDays,
            ],
        ]);
    }

    /**
     * POST /api/media/{id}/mark-published — trace une publication.
     */
    public function markPublished(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'post_id' => 'nullable|exists:posts,id',
            'thread_segment_id' => 'nullable|exists:thread_segments,id',
            'post_platform_id' => 'nullable|exists:post_platform,id',
            'external_url' => 'nullable|string|max:1000',
            'published_at' => 'nullable|date',
            'context' => 'nullable|string|max:255',
        ]);

        $publication = MediaPublication::create([
            'media_file_id' => $media->id,
            'post_id' => $data['post_id'] ?? null,
            'thread_segment_id' => $data['thread_segment_id'] ?? null,
            'post_platform_id' => $data['post_platform_id'] ?? null,
            'external_url' => $data['external_url'] ?? null,
            'published_at' => $data['published_at'] ?? now(),
            'context' => $data['context'] ?? null,
        ]);

        return response()->json([
            'id' => $publication->id,
            'media_file_id' => $media->id,
            'published_at' => $publication->published_at->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/media/pending-analysis — liste les photos qui attendent un enrichissement IA.
     * Utilisé par le script Mac pour rattraper les uploads web et les imports.
     */
    public function pendingAnalysis(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 500));

        $items = MediaFile::where('pending_analysis', true)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'filename', 'mime_type', 'source', 'original_name', 'created_at']);

        return response()->json([
            'items' => $items->map(fn ($m) => [
                'id' => $m->id,
                'filename' => $m->filename,
                'mime_type' => $m->mime_type,
                'source' => $m->source,
                'original_name' => $m->original_name,
                'created_at' => $m->created_at?->toIso8601String(),
                'url_full' => "/media/{$m->filename}",
            ]),
            'total_pending' => MediaFile::where('pending_analysis', true)
                ->where('mime_type', 'like', 'image/%')
                ->count(),
        ]);
    }

    /**
     * POST /api/media/{id}/enrich — pose les métadonnées IA sur une photo legacy.
     */
    public function enrich(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'description_fr' => 'nullable|string',
            'thematic_tags' => 'nullable|array',
            'thematic_tags.*' => 'string',
            'embedding' => 'required|array|min:1',
            'embedding.*' => 'numeric',
            'embedding_model' => 'nullable|string|max:50',
            'pool_suggested' => ['nullable', Rule::in([...self::ALL_POOLS, 'both', 'none'])],
            'people_ids' => 'nullable|array',
            'people_ids.*' => 'string',
            'intimacy_level' => ['nullable', Rule::in(self::INTIMACY_LEVELS)],
            'allow_wildycaro' => 'nullable|boolean',
            'allow_pdc_vantour' => 'nullable|boolean',
            'allow_mamawette' => 'nullable|boolean',
            'ai_metadata' => 'nullable|array',
            'phash' => 'nullable|string|max:64',
        ]);

        $media->update([
            'description_fr' => $data['description_fr'] ?? $media->description_fr,
            'thematic_tags' => isset($data['thematic_tags'])
                ? $this->normalizeTags($data['thematic_tags'])
                : $media->thematic_tags,
            'embedding' => $data['embedding'],
            'embedding_model' => $data['embedding_model'] ?? $media->embedding_model,
            'pool_suggested' => $data['pool_suggested'] ?? $media->pool_suggested,
            'people_ids' => $data['people_ids'] ?? $media->people_ids,
            'intimacy_level' => $data['intimacy_level'] ?? $media->intimacy_level,
            'allow_wildycaro' => $data['allow_wildycaro'] ?? $media->allow_wildycaro,
            'allow_pdc_vantour' => $data['allow_pdc_vantour'] ?? $media->allow_pdc_vantour,
            'allow_mamawette' => $data['allow_mamawette'] ?? $media->allow_mamawette,
            'ai_metadata' => $data['ai_metadata'] ?? $media->ai_metadata,
            'phash' => $data['phash'] ?? $media->phash,
            'pending_analysis' => false,
            'ingested_at' => $media->ingested_at ?? now(),
        ]);

        return response()->json([
            'id' => $media->id,
            'status' => 'enriched',
            'pending_analysis' => false,
        ]);
    }

    /**
     * GET /api/stock-photos/search — agrège Pexels + Pixabay + Unsplash.
     * Les images ne sont pas stockées localement : on retourne juste les URLs et l'attribution.
     */
    public function stockPhotosSearch(Request $request, StockPhotoService $stock): JsonResponse
    {
        $params = $request->validate([
            'q' => 'required|string|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:30',
            'source' => 'nullable|in:pexels,pixabay,unsplash,all',
        ]);

        $query = trim($params['q']);
        $limit = $params['limit'] ?? 8;
        $source = $params['source'] ?? 'all';

        $results = match ($source) {
            'pexels' => $stock->searchPexels($query, $limit),
            'pixabay' => $stock->searchPixabay($query, $limit),
            'unsplash' => $stock->searchUnsplash($query, $limit),
            default => $stock->searchAll($query, $limit),
        };

        return response()->json([
            'query' => $query,
            'count' => count($results),
            'available_providers' => $stock->availableProviders(),
            'results' => $results,
        ]);
    }

    /**
     * Atomise et normalise une liste de tags reçus :
     * - éclate les chaînes contenant ':' (drop le préfixe) ou ','
     * - lowercase + trim + élagage des ponctuations en bordure
     * - dédup case-insensitive, préserve l'ordre
     * - drop les tags > 60 chars
     */
    private function normalizeTags(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        $seen = [];
        $out = [];
        $push = function (string $s) use (&$seen, &$out) {
            $clean = trim(strtolower($s));
            $clean = trim($clean, "\"'.- ");
            if ($clean === '' || mb_strlen($clean) > 60 || isset($seen[$clean])) {
                return;
            }
            $seen[$clean] = true;
            $out[] = $clean;
        };

        foreach ($tags as $t) {
            if (! is_string($t)) {
                continue;
            }
            // Si présence de ':', on jette la clé et on garde la valeur.
            if (str_contains($t, ':')) {
                $t = explode(':', $t, 2)[1];
            }
            // Si virgule, on splitte.
            if (str_contains($t, ',')) {
                foreach (explode(',', $t) as $part) {
                    $push($part);
                }
            } else {
                $push($t);
            }
        }

        return $out;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }
        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
