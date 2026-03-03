<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProfilePictureService
{
    /**
     * Download a remote profile picture and store it locally.
     *
     * @param  string  $remoteUrl  The remote URL to download from
     * @param  string  $platformSlug  e.g. 'facebook', 'instagram', 'threads', 'twitter', 'youtube', 'bluesky'
     * @param  string  $accountId  The platform_account_id for consistent filenames
     * @return string|null Local path (/media/pp_{slug}_{hash}.{ext}) or null on failure
     */
    public static function download(string $remoteUrl, string $platformSlug, string $accountId): ?string
    {
        if (! $remoteUrl) {
            return null;
        }

        // Already a local path — skip
        if (str_starts_with($remoteUrl, '/media/')) {
            return $remoteUrl;
        }

        try {
            $response = Http::timeout(15)->get($remoteUrl);

            if (! $response->successful()) {
                Log::warning('ProfilePictureService: download failed', [
                    'url' => $remoteUrl,
                    'status' => $response->status(),
                    'platform' => $platformSlug,
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type');
            $extension = self::extensionFromMime($contentType) ?? self::extensionFromUrl($remoteUrl) ?? 'jpg';

            $filename = 'pp_' . $platformSlug . '_' . abs(crc32($accountId)) . '.' . $extension;

            // Delete old file if exists (same account = same filename)
            if (Storage::disk('local')->exists("media/{$filename}")) {
                Storage::disk('local')->delete("media/{$filename}");
            }

            Storage::disk('local')->put("media/{$filename}", $response->body());

            return "/media/{$filename}";
        } catch (\Throwable $e) {
            Log::warning('ProfilePictureService: exception', [
                'platform' => $platformSlug,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function extensionFromMime(?string $mime): ?string
    {
        if (! $mime) {
            return null;
        }

        return match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            default => null,
        };
    }

    private static function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! $path) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? ($ext === 'jpeg' ? 'jpg' : $ext) : null;
    }
}
