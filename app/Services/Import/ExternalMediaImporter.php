<?php

namespace App\Services\Import;

use App\Concerns\ProcessesImages;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Services\Media\PerceptualHasher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Rapatrie les photos d'une publication adoptee, une seule fois.
 *
 * Une meme publication partie sur quatre reseaux donne quatre URLs distinctes
 * pour la meme photo, chacune re-encodee par son reseau. La deduplication se
 * fait donc en cascade, du moins cher au plus cher :
 *
 *   1. URL deja connue      -> aucun telechargement
 *   2. SHA-256 des octets   -> identique parfait (meme reseau, deux passages)
 *   3. dHash perceptuel     -> meme photo re-encodee par un AUTRE reseau
 *
 * Les videos ne sont pas rapatriees : on garde leur miniature, qui suffit a
 * l'affichage, et le fichier source resterait de toute facon inaccessible chez
 * la plupart des reseaux.
 */
class ExternalMediaImporter
{
    use ProcessesImages;

    /** Au-dela, ce sont deux photos differentes. */
    private const DHASH_TOLERANCE = 6;

    /** Photos deja traitees pendant CE lot, par URL. */
    private array $seenUrls = [];

    public function __construct(private readonly PerceptualHasher $hasher) {}

    /**
     * @param  array<int, array>  $mediaItems  Items {url, type, thumbnail_url} agreges
     *                                         depuis toutes les publications cochees.
     * @return array{media: array<int, array{url: string, mimetype: string}>, files: array<int, MediaFile>, downloaded: int, reused: int, skipped_videos: int}
     */
    public function import(array $mediaItems): array
    {
        $this->seenUrls = [];

        $media = [];
        $files = [];
        $downloaded = 0;
        $reused = 0;
        $skippedVideos = 0;

        foreach ($mediaItems as $item) {
            $url = $item['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (($item['type'] ?? 'image') === 'video') {
                $skippedVideos++;

                continue;
            }

            if (array_key_exists($url, $this->seenUrls)) {
                continue;
            }

            [$file, $wasDownloaded] = $this->resolve($url);
            $this->seenUrls[$url] = $file?->id;

            if (! $file) {
                continue;
            }

            // Deux URLs differentes peuvent retomber sur le meme MediaFile.
            if (isset($files[$file->id])) {
                continue;
            }

            $wasDownloaded ? $downloaded++ : $reused++;

            $files[$file->id] = $file;
            $media[] = [
                'url' => '/media/'.$file->filename,
                'mimetype' => $file->mime_type,
            ];
        }

        return [
            'media' => $media,
            'files' => array_values($files),
            'downloaded' => $downloaded,
            'reused' => $reused,
            'skipped_videos' => $skippedVideos,
        ];
    }

    /**
     * @return array{0: ?MediaFile, 1: bool} le fichier, et s'il a fallu le telecharger
     */
    private function resolve(string $url): array
    {
        // 1. Cette URL exacte a deja ete rapatriee un jour.
        $byUrl = MediaFile::where('source_url', $url)->first();

        if ($byUrl) {
            return [$byUrl, false];
        }

        $body = $this->fetch($url);

        if ($body === null) {
            return [null, false];
        }

        // 2. Octets strictement identiques.
        $contentHash = hash('sha256', $body);
        $byContent = MediaFile::where('content_hash', $contentHash)->first();

        if ($byContent) {
            return [$byContent, false];
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'rsext_');
        file_put_contents($tempPath, $body);

        // 3. Meme photo, encodage different : le cas d'une publication
        //    croisee entre deux reseaux.
        $dhash = $this->hasher->hash($tempPath);
        $byDhash = $this->findByDhash($dhash);

        if ($byDhash) {
            @unlink($tempPath);

            return [$byDhash, false];
        }

        $file = $this->store($url, $tempPath, $contentHash, $dhash);
        @unlink($tempPath);

        return [$file, $file !== null];
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RS-Max/1.0; +https://rs-max.app)',
                    'Accept' => 'image/*',
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::info('ExternalMediaImporter: telechargement refuse', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $mime = explode(';', $response->header('Content-Type', ''))[0];

            if (! str_starts_with($mime, 'image/')) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('ExternalMediaImporter: exception au telechargement', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cherche une photo perceptuellement identique parmi celles dont on connait
     * le dHash. La comparaison se fait en PHP : le nombre de candidats reste
     * petit (medias rapatries depuis les reseaux), et aucune base SQL ne sait
     * indexer une distance de Hamming.
     */
    private function findByDhash(?string $dhash): ?MediaFile
    {
        if (! $dhash) {
            return null;
        }

        $candidates = MediaFile::whereNotNull('dhash')
            ->select(['id', 'dhash'])
            ->get();

        foreach ($candidates as $candidate) {
            $distance = $this->hasher->distance($dhash, $candidate->dhash);

            if ($distance !== null && $distance <= self::DHASH_TOLERANCE) {
                return MediaFile::find($candidate->id);
            }
        }

        return null;
    }

    private function store(string $url, string $tempPath, string $contentHash, ?string $dhash): ?MediaFile
    {
        $mime = mime_content_type($tempPath) ?: 'image/jpeg';
        $extension = $this->outputExtension($mime, $tempPath);
        $filename = date('Ymd_His').'_'.Str::random(8).'.'.$extension;

        $result = $this->processImage($tempPath, $mime, $filename);

        if (! ($result['success'] ?? false)) {
            Log::warning('ExternalMediaImporter: traitement image echoue', [
                'url' => $url,
                'error' => $result['error'] ?? null,
            ]);

            return null;
        }

        return MediaFile::create([
            'folder_id' => MediaFolder::ensureDefaultFolder()->id,
            'filename' => $result['filename'] ?? $filename,
            'original_name' => basename(parse_url($url, PHP_URL_PATH) ?: 'image'),
            'mime_type' => $result['mimetype'],
            'size' => $result['size'],
            'width' => $result['width'],
            'height' => $result['height'],
            'source' => 'external_import',
            'source_url' => $url,
            'content_hash' => $contentHash,
            'dhash' => $dhash,
        ]);
    }
}
