<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Adapters\BlueskyAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Temporary controller for one-time Instagram → Bluesky cross-posting.
 * Delete after use.
 */
class CrossPostController extends Controller
{
    private const IG_API = 'https://graph.facebook.com/v21.0';

    /**
     * Account pairs: Instagram ID => Bluesky ID.
     */
    private const PAIRS = [
        21 => 52, // wearealgarve → We Are Algarve (Bluesky)
        25 => 51, // the.van.tour → VanTour (Bluesky)
    ];

    public function index(): View
    {
        $pairs = [];
        foreach (self::PAIRS as $igId => $bsId) {
            $pairs[] = [
                'instagram' => SocialAccount::find($igId),
                'bluesky' => SocialAccount::find($bsId),
            ];
        }

        return view('tools.crosspost', compact('pairs'));
    }

    /**
     * Fetch all Instagram posts for an account (oldest first).
     */
    public function fetchPosts(Request $request): JsonResponse
    {
        $request->validate(['instagram_id' => 'required|integer']);
        $igId = (int) $request->input('instagram_id');

        if (! isset(self::PAIRS[$igId])) {
            return response()->json(['error' => 'Compte non autorisé.'], 403);
        }

        $account = SocialAccount::findOrFail($igId);
        $credentials = $account->credentials;
        $accessToken = $credentials['access_token'] ?? null;

        if (! $accessToken) {
            return response()->json(['error' => 'Pas de token Instagram.'], 422);
        }

        $posts = [];
        $url = self::IG_API . "/{$account->platform_account_id}/media";
        $params = [
            'fields' => 'id,caption,media_type,media_url,permalink,timestamp,children{id,media_type,media_url}',
            'limit' => 100,
            'access_token' => $accessToken,
        ];

        do {
            $response = Http::get($url, $params);
            if (! $response->successful()) {
                Log::error('CrossPost: IG API error', ['status' => $response->status(), 'body' => $response->body()]);
                break;
            }

            $data = $response->json();
            foreach ($data['data'] ?? [] as $item) {
                $mediaType = $item['media_type'] ?? 'IMAGE';

                $mediaItems = [];
                if ($mediaType === 'CAROUSEL_ALBUM') {
                    foreach ($item['children']['data'] ?? [] as $child) {
                        $mediaItems[] = [
                            'type' => $child['media_type'] ?? 'IMAGE',
                            'url' => $child['media_url'] ?? null,
                        ];
                    }
                } elseif (! empty($item['media_url'])) {
                    $mediaItems[] = [
                        'type' => $mediaType,
                        'url' => $item['media_url'],
                    ];
                }

                $posts[] = [
                    'id' => $item['id'],
                    'caption' => $item['caption'] ?? '',
                    'media_type' => $mediaType,
                    'permalink' => $item['permalink'] ?? '',
                    'timestamp' => $item['timestamp'] ?? '',
                    'media' => $mediaItems,
                ];
            }

            $url = $data['paging']['next'] ?? null;
            $params = [];
        } while ($url);

        // Reverse to get oldest first
        $posts = array_reverse($posts);

        return response()->json([
            'success' => true,
            'count' => count($posts),
            'posts' => $posts,
        ]);
    }

    /**
     * Cross-post a single Instagram post to Bluesky.
     */
    public function crossPost(Request $request): JsonResponse
    {
        $request->validate([
            'instagram_id' => 'required|integer',
            'post_id' => 'required|string',
            'caption' => 'nullable|string',
            'media' => 'nullable|array',
            'media.*.type' => 'required|string',
            'media.*.url' => 'required|url',
        ]);

        $igId = (int) $request->input('instagram_id');
        if (! isset(self::PAIRS[$igId])) {
            return response()->json(['error' => 'Compte non autorisé.'], 403);
        }

        $bsAccount = SocialAccount::findOrFail(self::PAIRS[$igId]);
        $adapter = new BlueskyAdapter;

        $caption = $request->input('caption', '');

        // Bluesky has 300 char limit — truncate if needed
        if (mb_strlen($caption) > 300) {
            $caption = mb_substr($caption, 0, 297) . '...';
        }

        $mediaItems = $request->input('media', []);
        $blueskyMedia = [];
        $tempFiles = [];

        try {
            foreach ($mediaItems as $item) {
                $mediaType = $item['type'] ?? 'IMAGE';
                $mediaUrl = $item['url'] ?? null;

                if (! $mediaUrl) {
                    continue;
                }

                // Download to temp file
                $tempFile = tempnam(sys_get_temp_dir(), 'xpost_');
                $tempFiles[] = $tempFile;

                $downloaded = Http::timeout(120)->withOptions(['sink' => $tempFile])->get($mediaUrl);
                if (! $downloaded->successful() || ! file_exists($tempFile) || filesize($tempFile) === 0) {
                    Log::warning('CrossPost: failed to download media', ['url' => $mediaUrl]);
                    continue;
                }

                $mimetype = mime_content_type($tempFile) ?: 'image/jpeg';

                if ($mediaType === 'VIDEO') {
                    // Bluesky video limit: ~50MB, but keep reasonable
                    $fileSize = filesize($tempFile);
                    if ($fileSize > 50_000_000) {
                        Log::warning('CrossPost: video too large, skipping', ['size' => $fileSize]);
                        continue;
                    }

                    $blueskyMedia[] = [
                        'url' => $mediaUrl,
                        'mimetype' => $mimetype,
                        'title' => '',
                    ];
                } else {
                    // Image — Bluesky handles compression in the adapter
                    $blueskyMedia[] = [
                        'url' => $mediaUrl,
                        'mimetype' => $mimetype,
                        'title' => '',
                    ];
                }
            }

            // Bluesky: max 4 images, or 1 video (no mix)
            $hasVideo = collect($blueskyMedia)->contains(fn ($m) => str_starts_with($m['mimetype'], 'video/'));

            if ($hasVideo) {
                // Keep only first video
                $blueskyMedia = [collect($blueskyMedia)->first(fn ($m) => str_starts_with($m['mimetype'], 'video/'))];
            } else {
                // Keep max 4 images
                $blueskyMedia = array_slice($blueskyMedia, 0, 4);
            }

            // Post to Bluesky
            $result = $adapter->publish(
                $bsAccount,
                $caption ?: '📸',
                ! empty($blueskyMedia) ? $blueskyMedia : null
            );

            return response()->json($result);

        } finally {
            // Clean up ALL temp files
            foreach ($tempFiles as $f) {
                if (file_exists($f)) {
                    @unlink($f);
                }
            }
        }
    }
}
