<?php

namespace App\Http\Controllers;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AiAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    use ProcessesImages;

    /**
     * Media library page.
     */
    public function index(Request $request): View
    {
        $filter = $request->input('filter', 'all');
        $folderId = $request->input('folder');

        // Filtre transversal "Jamais publier" — porte sur intimacy_level (par photo),
        // indépendant du dossier. Remplace l'ancien pool=never_publish.
        $intimacyFilter = $request->input('intimacy');

        $query = MediaFile::with('folder')->latest();

        if ($folderId === 'uncategorized') {
            $query->whereNull('folder_id');
        } elseif ($folderId) {
            // Filtrage récursif : inclut le dossier ET tous ses descendants.
            $rootFolder = MediaFolder::find($folderId);
            if ($rootFolder) {
                $query->whereIn('folder_id', $rootFolder->descendantIds());
            } else {
                $query->where('folder_id', $folderId);
            }
        }

        if ($filter === 'images') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($filter === 'videos') {
            $query->where('mime_type', 'like', 'video/%');
        } elseif ($filter === 'unclassified') {
            // Alias rétro-compat : photos sans dossier.
            $query->whereNull('folder_id');
        }

        if ($intimacyFilter === 'never_publish') {
            $query->where('intimacy_level', 'never_publish');
        }

        $mediaFiles = $query->get();

        $items = $mediaFiles->map(function (MediaFile $mf) {
            $isImage = $mf->is_image;
            $isVideo = $mf->is_video;

            $item = [
                'id' => $mf->id,
                'filename' => $mf->filename,
                'url' => $mf->url,
                'mimetype' => $mf->mime_type,
                'size' => $mf->size,
                'size_human' => $mf->size_human,
                'is_image' => $isImage,
                'is_video' => $isVideo,
                'type' => $isImage ? 'image' : ($isVideo ? 'video' : 'other'),
                'date' => $mf->created_at->format('d/m/Y H:i'),
                'folder_id' => $mf->folder_id,
                'folder_name' => $mf->folder?->name,
                'folder_color' => $mf->folder?->color,
                'width' => $mf->width,
                'height' => $mf->height,
                'source' => $mf->source,
                'description_fr' => $mf->description_fr,
                'thematic_tags' => $mf->thematic_tags ?? [],
                'people_ids' => $mf->people_ids ?? [],
                'folder_is_private' => $mf->folder?->is_private ?? false,
                'intimacy_level' => $mf->intimacy_level,
                'pending_analysis' => (bool) $mf->pending_analysis,
                'source_context' => $mf->source_context,
                'city' => $mf->city,
                'region' => $mf->region,
                'country' => $mf->country,
                'brands' => $mf->brands ?? [],
                'event' => $mf->event,
                'taken_at' => $mf->taken_at?->format('Y-m-d'),
                'taken_at_label' => $mf->taken_at?->locale('fr')->isoFormat('MMMM YYYY'),
                'publication_count' => (int) $mf->publication_count,
            ];

            if ($isVideo) {
                $item['thumbnail_url'] = route('media.thumbnail', $mf->filename);
            }

            return $item;
        })->values();

        // Find which posts reference each media file
        $posts = Post::whereNotNull('media')->with('postPlatforms.platform')->get();
        $mediaPostMap = [];

        foreach ($posts as $post) {
            if (! is_array($post->media)) {
                continue;
            }
            foreach ($post->media as $mediaItem) {
                $url = is_string($mediaItem) ? $mediaItem : ($mediaItem['url'] ?? '');
                $fname = basename($url);
                if ($fname) {
                    $statusDotClass = match ($post->status) {
                        'draft' => 'bg-gray-400',
                        'scheduled' => 'bg-blue-500',
                        'publishing' => 'bg-yellow-500',
                        'published' => 'bg-green-500',
                        'failed' => 'bg-red-500',
                        default => 'bg-gray-300',
                    };
                    $platforms = $post->postPlatforms->map(fn ($pp) => [
                        'slug' => $pp->platform->slug,
                        'name' => $pp->platform->name,
                        'status' => $pp->status,
                    ])->values()->toArray();
                    $mediaPostMap[$fname][] = [
                        'id' => $post->id,
                        'preview' => Str::limit($post->content_fr, 50),
                        'status' => $post->status,
                        'status_dot' => $statusDotClass,
                        'platforms' => $platforms,
                        'scheduled_at' => $post->scheduled_at?->format('d/m/Y H:i'),
                        'published_at' => $post->published_at?->format('d/m/Y H:i'),
                    ];
                }
            }
        }

        $folders = MediaFolder::ordered()->withCount('files')->get();
        $imageCount = MediaFile::where('mime_type', 'like', 'image/%')->count();
        $videoCount = MediaFile::where('mime_type', 'like', 'video/%')->count();
        $totalCount = MediaFile::count();
        $uncategorizedCount = MediaFile::whereNull('folder_id')->count();
        $neverPublishCount = MediaFile::where('mime_type', 'like', 'image/%')
            ->where('intimacy_level', 'never_publish')
            ->count();

        $currentFolder = $folderId;
        $currentIntimacy = $intimacyFilter;

        return view('media.index', compact(
            'items', 'mediaPostMap', 'filter', 'imageCount', 'videoCount',
            'folders', 'totalCount', 'uncategorizedCount', 'neverPublishCount',
            'currentFolder', 'currentIntimacy'
        ));
    }

    /**
     * Sous-page d'édition en masse. Même grille / sidebars que /media,
     * mais panneau d'édition multiple à droite (tags ±, brands ±, people ±,
     * city/region/country/event, intimacy, allow_*). Multi-select forcé.
     */
    public function manage(Request $request): View
    {
        $folderId = $request->input('folder');
        $intimacyFilter = $request->input('intimacy');

        $query = MediaFile::with('folder')->latest();

        if ($folderId === 'uncategorized') {
            $query->whereNull('folder_id');
        } elseif ($folderId) {
            $rootFolder = MediaFolder::find($folderId);
            $query->whereIn('folder_id', $rootFolder ? $rootFolder->descendantIds() : [(int) $folderId]);
        }

        if ($intimacyFilter === 'never_publish') {
            $query->where('intimacy_level', 'never_publish');
        }

        $items = $query->get()->map(fn (MediaFile $mf) => [
            'id' => $mf->id,
            'filename' => $mf->filename,
            'url' => $mf->url,
            'is_image' => $mf->is_image,
            'is_video' => $mf->is_video,
            'thumbnail_url' => $mf->is_video ? route('media.thumbnail', $mf->filename) : null,
            'size_human' => $mf->size_human,
            'date' => $mf->created_at->format('d/m/Y'),
            'folder_id' => $mf->folder_id,
            'folder_name' => $mf->folder?->name,
            'folder_color' => $mf->folder?->color,
            'description_fr' => $mf->description_fr,
            'thematic_tags' => $mf->thematic_tags ?? [],
            'people_ids' => $mf->people_ids ?? [],
            'brands' => $mf->brands ?? [],
            'city' => $mf->city,
            'region' => $mf->region,
            'country' => $mf->country,
            'event' => $mf->event,
            'folder_is_private' => $mf->folder?->is_private ?? false,
            'intimacy_level' => $mf->intimacy_level,
            'publication_count' => (int) $mf->publication_count,
        ])->values();

        $folders = MediaFolder::ordered()->withCount('files')->get();
        $totalCount = MediaFile::count();
        $uncategorizedCount = MediaFile::whereNull('folder_id')->count();
        $neverPublishCount = MediaFile::where('intimacy_level', 'never_publish')->count();

        return view('media.manage', [
            'items' => $items,
            'folders' => $folders,
            'totalCount' => $totalCount,
            'uncategorizedCount' => $uncategorizedCount,
            'neverPublishCount' => $neverPublishCount,
            'currentFolder' => $folderId,
            'currentIntimacy' => $intimacyFilter,
        ]);
    }

    /**
     * POST /media/brands-batch — ajoute/retire des marques sur plusieurs photos.
     * Body : ids[], add[], remove[]. Casse d'origine préservée à l'ajout, dédup case-insensitive.
     */
    public function brandsBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'add' => 'nullable|array',
            'add.*' => 'string|max:80',
            'remove' => 'nullable|array',
            'remove.*' => 'string|max:80',
        ]);

        $addClean = collect($data['add'] ?? [])->map(fn ($b) => trim((string) $b))->filter()->all();
        $removeKeys = collect($data['remove'] ?? [])->map(fn ($b) => mb_strtolower(trim((string) $b)))->filter()->all();

        $files = MediaFile::whereIn('id', $data['ids'])->get();
        foreach ($files as $mf) {
            $current = collect($mf->brands ?? [])->filter(fn ($b) => is_string($b) && trim($b) !== '');
            // remove
            if ($removeKeys) {
                $current = $current->reject(fn ($b) => in_array(mb_strtolower(trim($b)), $removeKeys, true));
            }
            // add
            $seen = $current->mapWithKeys(fn ($b) => [mb_strtolower(trim($b)) => true])->all();
            foreach ($addClean as $b) {
                $key = mb_strtolower($b);
                if (! isset($seen[$key])) {
                    $current->push($b);
                    $seen[$key] = true;
                }
            }
            $mf->update(['brands' => $current->values()->all()]);
        }

        return response()->json([
            'count' => $files->count(),
            'added' => $addClean,
            'removed' => $data['remove'] ?? [],
        ]);
    }

    /**
     * POST /media/people-batch — ajoute/retire des people_ids sur plusieurs photos.
     * Body : ids[], add[], remove[]. Lowercase + trim.
     */
    public function peopleBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'add' => 'nullable|array',
            'add.*' => 'string|max:50',
            'remove' => 'nullable|array',
            'remove.*' => 'string|max:50',
        ]);

        $addClean = collect($data['add'] ?? [])->map(fn ($p) => mb_strtolower(trim((string) $p)))->filter()->unique()->values()->all();
        $removeClean = collect($data['remove'] ?? [])->map(fn ($p) => mb_strtolower(trim((string) $p)))->filter()->all();

        $files = MediaFile::whereIn('id', $data['ids'])->get();
        foreach ($files as $mf) {
            $current = collect($mf->people_ids ?? [])->map(fn ($p) => mb_strtolower(trim((string) $p)))->filter();
            foreach ($addClean as $p) {
                $current->push($p);
            }
            if ($removeClean) {
                $current = $current->reject(fn ($p) => in_array($p, $removeClean, true));
            }
            $mf->update(['people_ids' => $current->unique()->values()->all()]);
        }

        return response()->json([
            'count' => $files->count(),
            'added' => $addClean,
            'removed' => $removeClean,
        ]);
    }

    /**
     * POST /media/{media}/analyze-vision — wrapper session-auth pour l'analyse Vision.
     * Réplique le comportement de MediaApiController::analyzeVision pour le frontend web.
     * Persiste tags/people/champs structurés extraits par l'IA sur la photo.
     */
    public function analyzeVision(Request $request, MediaFile $media, AiAssistService $ai): JsonResponse
    {
        $data = $request->validate([
            'expected_people' => 'nullable|array',
            'expected_people.*' => 'string|max:50',
            'context' => 'nullable|string|max:500',
        ]);

        if (! $media->is_image) {
            return response()->json(['error' => 'analyze-vision ne supporte que les images'], 422);
        }

        $candidates = [
            Storage::disk('local')->path("media/{$media->filename}"),
            storage_path("app/media/{$media->filename}"),
            Storage::disk('public')->path("media/{$media->filename}"),
        ];
        $absolutePath = null;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $absolutePath = $path;
                break;
            }
        }
        if ($absolutePath === null) {
            return response()->json(['error' => 'fichier image introuvable sur le storage'], 404);
        }

        $dataUrl = $this->resizeImageForVision($absolutePath);
        if ($dataUrl === null) {
            return response()->json(['error' => 'impossible de charger l\'image'], 500);
        }

        $context = $data['context'] ?? ($media->folder ? $media->folder->pathLabel() : null);

        $result = $ai->extractMetadataFromImage(
            imageDataUrl: $dataUrl,
            context: $context,
            expectedPeople: $data['expected_people'] ?? [],
        );

        if ($result === null) {
            return response()->json(['error' => 'analyse Vision a échoué (voir logs)'], 502);
        }
        if ($result === 'refused') {
            return response()->json(['error' => 'tous les modèles Vision ont refusé l\'analyse'], 422);
        }

        $update = [];
        if ($result['description_fr'] !== null) {
            $update['description_fr'] = $result['description_fr'];
        }
        if (! empty($result['thematic_tags'])) {
            // Overwrite : l'IA propose une liste fraîche, on remplace l'existant.
            $update['thematic_tags'] = collect($result['thematic_tags'])
                ->map(fn ($t) => mb_strtolower(trim((string) $t)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        if (! empty($result['people_ids'])) {
            $update['people_ids'] = $result['people_ids'];
        }
        if ($result['city'] !== null) {
            $update['city'] = $result['city'];
        }
        if ($result['region'] !== null) {
            $update['region'] = $result['region'];
        }
        if ($result['country'] !== null) {
            $update['country'] = $result['country'];
        }
        if (! empty($result['brands'])) {
            $update['brands'] = $result['brands'];
        }
        if ($result['event'] !== null) {
            $update['event'] = $result['event'];
        }
        if (! empty($result['taken_at'])) {
            try {
                $update['taken_at'] = \Carbon\Carbon::parse($result['taken_at']);
            } catch (\Throwable $e) {
                // Date non parsable -> on ignore plutot que de casser l'analyse.
            }
        }
        $aiMeta = is_array($media->ai_metadata) ? $media->ai_metadata : [];
        $aiMeta['vision_analysis'] = [
            'analyzed_at' => now()->toIso8601String(),
            'folder_id' => $media->folder_id,
            'person_count' => $result['person_count'],
        ];
        $update['ai_metadata'] = $aiMeta;
        $update['pending_analysis'] = false;

        $media->update($update);
        $media->refresh();

        // Renvoie la photo mise à jour pour que le frontend puisse refresh sans reload.
        return response()->json([
            'id' => $media->id,
            'thematic_tags' => $media->thematic_tags ?? [],
            'people_ids' => $media->people_ids ?? [],
            'brands' => $media->brands ?? [],
            'city' => $media->city,
            'region' => $media->region,
            'country' => $media->country,
            'event' => $media->event,
            'description_fr' => $media->description_fr,
            'taken_at' => $media->taken_at?->toIso8601String(),
        ]);
    }

    /**
     * Helper privé : redimensionne à 1024px max et renvoie une data URL JPEG base64.
     * Dupliqué de MediaApiController et AiAssistController. À extraire en trait
     * si on l'utilise une 4ème fois.
     */
    private function resizeImageForVision(string $filePath): ?string
    {
        $mimeType = mime_content_type($filePath);
        $image = match ($mimeType) {
            'image/png' => @imagecreatefrompng($filePath),
            'image/gif' => @imagecreatefromgif($filePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => @imagecreatefromjpeg($filePath),
        };
        if (! $image) {
            return null;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $maxDim = 1024;
        if ($w > $maxDim || $h > $maxDim) {
            if ($w >= $h) {
                $newW = $maxDim;
                $newH = (int) round($h * ($maxDim / $w));
            } else {
                $newH = $maxDim;
                $newW = (int) round($w * ($maxDim / $h));
            }
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($image);
            $image = $resized;
        }
        ob_start();
        imagejpeg($image, null, 80);
        $data = ob_get_clean();
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode($data);
    }

    /**
     * POST /media/details-batch — applique des champs structurés à plusieurs photos.
     * Body : ids[], + n'importe lesquels de city, region, country, event, intimacy_level.
     * La visibilité API est désormais portée par le dossier (cf. media.folders.update),
     * pas par la photo. Pour déplacer plusieurs photos : media.folders.move.
     */
    public function detailsBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'event' => 'nullable|string|max:200',
            'intimacy_level' => 'nullable|in:public,prive,never_publish',
            'taken_at' => 'nullable|date',
        ]);

        $update = [];
        foreach (['city', 'region', 'country', 'event'] as $field) {
            if ($request->exists($field)) {
                $val = $data[$field] ?? null;
                $update[$field] = is_string($val) && trim($val) !== '' ? trim($val) : null;
            }
        }
        if ($request->exists('intimacy_level')) {
            $update['intimacy_level'] = $data['intimacy_level'] ?? 'public';
        }
        if ($request->exists('taken_at')) {
            $update['taken_at'] = ! empty($data['taken_at']) ? \Carbon\Carbon::parse($data['taken_at']) : null;
        }

        if (empty($update)) {
            return response()->json(['error' => 'aucun champ à mettre à jour'], 422);
        }

        $count = MediaFile::whereIn('id', $data['ids'])->update($update);

        return response()->json([
            'count' => $count,
            'fields' => array_keys($update),
        ]);
    }

    /**
     * Upload a media file (AJAX). Images are compressed with GD.
     */
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        $mimeType = $file?->getMimeType() ?? '';
        $isImage = str_starts_with($mimeType, 'image/');

        // Read max upload size from app settings (MB → KB).
        $maxKb = $isImage
            ? (int) Setting::get('image_max_upload_mb', 50) * 1024
            : (int) Setting::get('video_max_upload_mb', 500) * 1024;

        $request->validate([
            'file' => "required|file|max:{$maxKb}|mimes:jpeg,jpg,png,gif,webp,mp4,mov,avi,webm",
            'folder_id' => 'nullable|exists:media_folders,id',
        ]);

        $originalName = $file->getClientOriginalName();
        $folderId = $request->input('folder_id');

        // Generate unique filename
        $extension = $isImage ? $this->outputExtension($mimeType, $file->getRealPath()) : $file->getClientOriginalExtension();
        $filename = date('Ymd_His').'_'.Str::random(8).'.'.$extension;

        if ($isImage) {
            // Compress and resize with GD
            $result = $this->processImage($file->getRealPath(), $mimeType, $filename);

            if (! $result['success']) {
                return response()->json(['error' => $result['error']], 422);
            }

            // Use the filename from processImage (may have changed for PNG→JPG)
            $finalFilename = $result['filename'] ?? $filename;

            MediaFile::create([
                'folder_id' => $folderId,
                'filename' => $finalFilename,
                'original_name' => $originalName,
                'mime_type' => $result['mimetype'],
                'size' => $result['size'],
                'width' => $result['width'],
                'height' => $result['height'],
                'source' => 'upload',
            ]);

            return response()->json([
                'url' => "/media/{$finalFilename}",
                'mimetype' => $result['mimetype'],
                'size' => $result['size'],
                'title' => $originalName,
                'width' => $result['width'],
                'height' => $result['height'],
            ]);
        }

        // Video: store, then compress to MP4 H.264/AAC using app settings.
        $file->storeAs('media', $filename, 'local');

        $compressed = $this->compressVideo($filename);
        if ($compressed) {
            $filename = $compressed['filename'];
            $mimeType = 'video/mp4';
        }

        $storedPath = Storage::disk('local')->path("media/{$filename}");
        $storedSize = Storage::disk('local')->size("media/{$filename}");
        $resolution = $this->getVideoResolution($storedPath);

        MediaFile::create([
            'folder_id' => $folderId,
            'filename' => $filename,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $storedSize,
            'width' => $resolution['width'] ?: null,
            'height' => $resolution['height'] ?: null,
            'source' => 'upload',
        ]);

        return response()->json([
            'url' => "/media/{$filename}",
            'mimetype' => $mimeType,
            'size' => $storedSize,
            'title' => $originalName,
            'thumbnail_url' => route('media.thumbnail', $filename),
        ]);
    }

    /**
     * Recherche dans les banques d'images externes (Pexels, Pixabay, Unsplash).
     * Web route — session auth, accessible depuis l'éditeur de threads.
     * Les images ne sont jamais stockées localement, on retourne les URLs et l'attribution.
     */
    public function searchStockPhotos(Request $request, \App\Services\StockPhotoService $stock): JsonResponse
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
     * POST /media/{id}/classify — déplace une photo dans un dossier et/ou modifie son intimacy.
     * `folder_id` null = retire du dossier (devient "à classer"). `intimacy_level` "never_publish"
     * marque la photo comme jamais publiable, indépendamment de la visibilité du dossier.
     */
    public function classify(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'folder_id' => 'nullable|integer|exists:media_folders,id',
            'intimacy_level' => 'nullable|in:public,prive,never_publish',
        ]);

        $update = [];
        if ($request->exists('folder_id')) {
            $update['folder_id'] = $data['folder_id'];
        }
        if (! empty($data['intimacy_level'])) {
            $update['intimacy_level'] = $data['intimacy_level'];
        }

        if (empty($update)) {
            return response()->json(['error' => 'aucun champ à mettre à jour'], 422);
        }

        $media->update($update);

        return response()->json([
            'id' => $media->id,
            'folder_id' => $media->folder_id,
            'intimacy_level' => $media->intimacy_level,
        ]);
    }

    /**
     * POST /media/classify-batch — applique folder_id et/ou intimacy_level à plusieurs photos.
     */
    public function classifyBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'folder_id' => 'nullable|integer|exists:media_folders,id',
            'intimacy_level' => 'nullable|in:public,prive,never_publish',
        ]);

        $update = [];
        if ($request->exists('folder_id')) {
            $update['folder_id'] = $data['folder_id'];
        }
        if (! empty($data['intimacy_level'])) {
            $update['intimacy_level'] = $data['intimacy_level'];
        }

        if (empty($update)) {
            return response()->json(['error' => 'aucun champ à mettre à jour'], 422);
        }

        $count = MediaFile::whereIn('id', $data['ids'])->update($update);

        return response()->json([
            'count' => $count,
            'ids' => $data['ids'],
            'fields' => array_keys($update),
        ]);
    }

    /**
     * PATCH /media/{id}/tags — ajoute/retire des tags sur une photo.
     */
    public function updateTags(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'add' => 'nullable|array',
            'add.*' => 'string|max:60',
            'remove' => 'nullable|array',
            'remove.*' => 'string|max:60',
        ]);

        $current = collect($media->thematic_tags ?? [])
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->filter();

        if (! empty($data['add'])) {
            foreach ($data['add'] as $t) {
                $clean = mb_strtolower(trim($t));
                if ($clean !== '' && mb_strlen($clean) <= 60) {
                    $current->push($clean);
                }
            }
        }

        if (! empty($data['remove'])) {
            $toRemove = collect($data['remove'])->map(fn ($t) => mb_strtolower(trim($t)))->all();
            $current = $current->reject(fn ($t) => in_array($t, $toRemove, true));
        }

        $tags = $current->unique()->values()->all();
        $media->update(['thematic_tags' => $tags]);

        return response()->json([
            'id' => $media->id,
            'thematic_tags' => $tags,
        ]);
    }

    /**
     * PATCH /media/{id}/details — édite les champs structurés (lieu, marques, événement, taken_at).
     */
    public function updateDetails(Request $request, MediaFile $media): JsonResponse
    {
        $data = $request->validate([
            'description_fr' => 'nullable|string|max:2000',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'brands' => 'nullable|array',
            'brands.*' => 'string|max:80',
            'event' => 'nullable|string|max:200',
            'taken_at' => 'nullable|date',
        ]);

        $update = [];
        foreach (['city', 'region', 'country', 'event'] as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                $update[$field] = is_string($val) && trim($val) !== '' ? trim($val) : null;
            }
        }
        if (array_key_exists('description_fr', $data)) {
            $val = $data['description_fr'];
            $update['description_fr'] = is_string($val) && trim($val) !== '' ? trim($val) : null;
        }
        if (array_key_exists('brands', $data)) {
            $seen = [];
            $brands = [];
            foreach ($data['brands'] ?? [] as $b) {
                $clean = trim((string) $b);
                if ($clean === '') {
                    continue;
                }
                $key = mb_strtolower($clean);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $brands[] = $clean;
            }
            $update['brands'] = $brands;
        }
        if (array_key_exists('taken_at', $data)) {
            $update['taken_at'] = $data['taken_at'] ?: null;
        }

        $media->update($update);

        return response()->json([
            'id' => $media->id,
            'description_fr' => $media->description_fr,
            'city' => $media->city,
            'region' => $media->region,
            'country' => $media->country,
            'brands' => $media->brands ?? [],
            'event' => $media->event,
            'taken_at' => $media->taken_at?->format('Y-m-d'),
            'taken_at_label' => $media->taken_at?->locale('fr')->isoFormat('MMMM YYYY'),
        ]);
    }

    /**
     * GET /media/autocomplete — valeurs distinctes existantes pour autocomplete UI.
     * Retourne villes, régions, pays, marques.
     */
    public function autocomplete(): JsonResponse
    {
        $cities = MediaFile::whereNotNull('city')->where('city', '!=', '')
            ->distinct()->orderBy('city')->limit(500)->pluck('city');
        $regions = MediaFile::whereNotNull('region')->where('region', '!=', '')
            ->distinct()->orderBy('region')->limit(500)->pluck('region');
        $countries = MediaFile::whereNotNull('country')->where('country', '!=', '')
            ->distinct()->orderBy('country')->limit(500)->pluck('country');

        // Marques : agréger les valeurs JSON distinctes (case-insensitive) sans dépendre du SQL JSON.
        $brandSet = [];
        MediaFile::whereNotNull('brands')->select('brands')->chunk(500, function ($rows) use (&$brandSet) {
            foreach ($rows as $row) {
                foreach ($row->brands ?? [] as $b) {
                    $clean = trim((string) $b);
                    if ($clean === '') {
                        continue;
                    }
                    $key = mb_strtolower($clean);
                    if (! isset($brandSet[$key])) {
                        $brandSet[$key] = $clean;
                    }
                }
            }
        });
        $brands = array_values($brandSet);
        sort($brands, SORT_NATURAL | SORT_FLAG_CASE);

        return response()->json([
            'cities' => $cities,
            'regions' => $regions,
            'countries' => $countries,
            'brands' => $brands,
        ]);
    }

    /**
     * POST /media/tags-batch — ajoute/retire des tags sur plusieurs photos.
     */
    public function tagsBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'add' => 'nullable|array',
            'add.*' => 'string|max:60',
            'remove' => 'nullable|array',
            'remove.*' => 'string|max:60',
        ]);

        $addClean = collect($data['add'] ?? [])
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->filter(fn ($t) => $t !== '' && mb_strlen($t) <= 60)
            ->unique()
            ->values()
            ->all();

        $removeClean = collect($data['remove'] ?? [])
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->filter()
            ->all();

        $files = MediaFile::whereIn('id', $data['ids'])->get();
        foreach ($files as $mf) {
            $tags = collect($mf->thematic_tags ?? [])
                ->map(fn ($t) => mb_strtolower(trim((string) $t)))
                ->filter();
            foreach ($addClean as $t) {
                $tags->push($t);
            }
            if ($removeClean) {
                $tags = $tags->reject(fn ($t) => in_array($t, $removeClean, true));
            }
            $mf->update(['thematic_tags' => $tags->unique()->values()->all()]);
        }

        return response()->json([
            'count' => $files->count(),
            'added' => $addClean,
            'removed' => $removeClean,
        ]);
    }

    /**
     * Supprime plusieurs médias d'un coup (DB + fichiers physiques).
     */
    public function deleteBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ]);

        $files = MediaFile::whereIn('id', $data['ids'])->get();
        $deletedIds = [];
        $missing = 0;

        foreach ($files as $mf) {
            $path = "media/{$mf->filename}";
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            } else {
                $missing++;
            }
            $mf->delete();
            $deletedIds[] = $mf->id;
        }

        return response()->json([
            'count' => count($deletedIds),
            'ids' => $deletedIds,
            'missing_files' => $missing,
        ]);
    }

    /**
     * Delete a media file. On supprime toujours l'entrée DB, même si le fichier
     * physique a déjà disparu (entrée orpheline résultant d'un upload échoué,
     * d'un nettoyage manuel ou d'une session bulk interrompue).
     */
    public function destroy(Request $request, string $filename): JsonResponse
    {
        $path = "media/{$filename}";
        $physicalDeleted = false;

        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
            $physicalDeleted = true;
        }

        $rowsDeleted = MediaFile::where('filename', $filename)->delete();

        if (! $physicalDeleted && $rowsDeleted === 0) {
            return response()->json(['error' => 'Fichier introuvable (ni sur disque, ni en base).'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => $physicalDeleted ? 'Fichier supprimé.' : 'Entrée DB supprimée (fichier absent du disque).',
        ]);
    }

    /**
     * List all media files as JSON (for the library picker in post forms).
     */
    public function list(Request $request): JsonResponse
    {
        $folderId = $request->input('folder');

        $query = MediaFile::with('folder')->latest();

        if ($folderId === 'uncategorized') {
            $query->whereNull('folder_id');
        } elseif ($folderId) {
            $query->where('folder_id', $folderId);
        }

        $items = $query->get()->map(function (MediaFile $mf) {
            $isVideo = $mf->is_video;

            $item = [
                'id' => $mf->id,
                'filename' => $mf->filename,
                'url' => $mf->url,
                'mimetype' => $mf->mime_type,
                'size' => $mf->size,
                'size_human' => $mf->size_human,
                'is_image' => $mf->is_image,
                'is_video' => $isVideo,
                'date' => $mf->created_at->format('d/m/Y H:i'),
                'folder_id' => $mf->folder_id,
                'folder_name' => $mf->folder?->name,
            ];

            if ($isVideo) {
                $item['thumbnail_url'] = route('media.thumbnail', $mf->filename);
            }

            return $item;
        })->values();

        $folders = MediaFolder::ordered()->withCount('files')->get()->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->name,
            'color' => $f->color,
            'files_count' => $f->files_count,
        ]);

        return response()->json([
            'items' => $items,
            'folders' => $folders,
        ]);
    }

    /**
     * Serve a video thumbnail (generated via ffmpeg, cached).
     */
    public function thumbnail(Request $request, string $filename): BinaryFileResponse
    {
        $videoPath = Storage::disk('local')->path("media/{$filename}");
        if (! file_exists($videoPath)) {
            abort(404);
        }

        $thumbDir = Storage::disk('local')->path('media/thumbnails');
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME).'.jpg';
        $thumbPath = $thumbDir.'/'.$thumbFilename;

        if (! file_exists($thumbPath)) {
            $ffmpeg = $this->findBinary('ffmpeg');
            if (! $ffmpeg) {
                abort(404);
            }

            // Extract frame at ~5 seconds
            exec(sprintf(
                '%s -ss 5 -i %s -frames:v 1 -q:v 3 -vf "scale=480:-1" -update 1 %s 2>/dev/null',
                escapeshellarg($ffmpeg),
                escapeshellarg($videoPath),
                escapeshellarg($thumbPath)
            ));

            // If 5s failed (video too short), try first frame
            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                exec(sprintf(
                    '%s -i %s -frames:v 1 -q:v 3 -vf "scale=480:-1" -update 1 %s 2>/dev/null',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($videoPath),
                    escapeshellarg($thumbPath)
                ));
            }

            if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
                abort(404);
            }
        }

        return response()->file($thumbPath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Serve a private media file. Accepte session web, Bearer Sanctum
     * (utilisé par le script Mac d'analyse) ou URL signée.
     */
    public function show(Request $request, string $filename): BinaryFileResponse
    {
        $user = $request->user() ?? \Illuminate\Support\Facades\Auth::guard('sanctum')->user();

        if (! $user && ! $request->hasValidSignature()) {
            abort(403);
        }

        $path = "media/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $fullPath = Storage::disk('local')->path($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Get video resolution (width, height) using ffprobe.
     */
    private function getVideoResolution(string $filePath): array
    {
        $ffprobe = $this->findBinary('ffprobe');
        if (! $ffprobe) {
            return ['width' => 0, 'height' => 0];
        }

        $output = [];
        exec(sprintf(
            '%s -v quiet -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        ), $output);

        $parts = explode(',', trim($output[0] ?? ''));

        return [
            'width' => (int) ($parts[0] ?? 0),
            'height' => (int) ($parts[1] ?? 0),
        ];
    }

    /**
     * Compress video to MP4 H.264/AAC using ffmpeg and app settings.
     */
    private function compressVideo(string $filename): ?array
    {
        $ffmpeg = $this->findBinary('ffmpeg');
        if (! $ffmpeg) {
            return null;
        }

        $originalPath = Storage::disk('local')->path("media/{$filename}");
        $originalSize = filesize($originalPath);
        $mp4Filename = pathinfo($filename, PATHINFO_FILENAME).'.mp4';

        // Read compression settings.
        $bitrate1080 = (int) Setting::get('video_bitrate_1080p', 6000);
        $bitrate720 = (int) Setting::get('video_bitrate_720p', 2500);
        $audioBitrate = (int) Setting::get('video_audio_bitrate', 128);

        // Choose bitrate based on resolution.
        $resolution = $this->getVideoResolution($originalPath);
        $videoBitrate = $resolution['height'] >= 1080 ? $bitrate1080 : $bitrate720;
        $maxRate = (int) ($videoBitrate * 1.5);
        $bufSize = $videoBitrate * 2;

        // Use temp file if input is already .mp4.
        $sameFile = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';
        $outputPath = $sameFile
            ? Storage::disk('local')->path("media/{$mp4Filename}.tmp.mp4")
            : Storage::disk('local')->path("media/{$mp4Filename}");

        // Cap longest side to 1920 (Meta/IG Reels max). Preserves aspect ratio,
        // never upscales, forces even dimensions required by libx264.
        $scaleFilter = "scale='if(gt(iw,ih),min(1920,iw),-2)':'if(gt(iw,ih),-2,min(1920,ih))'";

        exec(sprintf(
            '%s -i %s -vf %s -c:v libx264 -preset fast -b:v %dk -maxrate %dk -bufsize %dk -c:a aac -b:a %dk -movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($originalPath),
            escapeshellarg($scaleFilter),
            $videoBitrate,
            $maxRate,
            $bufSize,
            $audioBitrate,
            escapeshellarg($outputPath)
        ), $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            $compressedSize = filesize($outputPath);

            // If source was already ≤1080p (Meta-compatible) AND compression didn't shrink it,
            // keep original — no downscale happened, just a re-encode that didn't help.
            $wasMetaCompatible = max($resolution['width'], $resolution['height']) <= 1920;

            if ($wasMetaCompatible && $compressedSize >= $originalSize && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4') {
                @unlink($outputPath);

                return null;
            }

            if ($sameFile) {
                @unlink($originalPath);
                rename($outputPath, Storage::disk('local')->path("media/{$mp4Filename}"));
            } else {
                @unlink($originalPath);
            }

            return ['filename' => $mp4Filename];
        }

        // Compression failed — keep original.
        @unlink($outputPath);

        return null;
    }

    /**
     * Locate a binary (ffmpeg, ffprobe, etc.).
     */
    private function findBinary(string $name): ?string
    {
        $path = trim(exec("which {$name} 2>/dev/null"));
        if ($path) {
            return $path;
        }

        foreach (["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}", "/usr/bin/{$name}"] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Format file size for display.
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }
}
