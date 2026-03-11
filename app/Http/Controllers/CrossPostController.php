<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Adapters\BlueskyAdapter;
use App\Services\Adapters\ThreadsAdapter;
use App\Services\AiAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Temporary controller for one-time Instagram → Bluesky + Threads cross-posting.
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
            $igAccount = SocialAccount::find($igId);
            $bsAccount = SocialAccount::find($bsId);

            // Auto-discover Threads account linked to same user(s)
            $threadsAccount = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'threads'))
                ->whereHas('users', fn ($q) => $q->whereIn('user_id', $igAccount->users->pluck('id')))
                ->first();

            $pairs[] = [
                'instagram' => $igAccount,
                'bluesky' => $bsAccount,
                'threads' => $threadsAccount,
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

        // Get already cross-posted IDs from cache
        $doneIds = Cache::get("crosspost_done_{$igId}", []);

        return response()->json([
            'success' => true,
            'count' => count($posts),
            'posts' => $posts,
            'done_ids' => $doneIds,
        ]);
    }

    /**
     * Cross-post a single Instagram post to Bluesky + Threads.
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
            'skip_platforms' => 'nullable|array',
            'skip_platforms.*' => 'string|in:bluesky,threads',
        ]);

        $igId = (int) $request->input('instagram_id');
        if (! isset(self::PAIRS[$igId])) {
            return response()->json(['error' => 'Compte non autorisé.'], 403);
        }

        $bsAccount = SocialAccount::findOrFail(self::PAIRS[$igId]);

        // Auto-discover Threads account linked to same user(s)
        $igAccount = SocialAccount::findOrFail($igId);
        $threadsAccount = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'threads'))
            ->whereHas('users', fn ($q) => $q->whereIn('user_id', $igAccount->users->pluck('id')))
            ->first();

        $skipPlatforms = $request->input('skip_platforms', []);
        $doBluesky = ! in_array('bluesky', $skipPlatforms);
        $doThreads = ! in_array('threads', $skipPlatforms) && $threadsAccount;

        $caption = $request->input('caption', '');

        // Bluesky caption: 300 char limit — use AI with persona to rewrite if needed
        $bsCaption = $caption;
        if (mb_strlen($bsCaption) > 300) {
            $persona = $bsAccount->persona;
            if ($persona) {
                $aiService = new AiAssistService;
                $aiCaption = $aiService->generate($bsCaption, $persona, $bsAccount);
                if ($aiCaption && mb_strlen($aiCaption) <= 300) {
                    $bsCaption = $aiCaption;
                } else {
                    $bsCaption = mb_substr($aiCaption ?: $bsCaption, 0, 297) . '...';
                }
            } else {
                $bsCaption = mb_substr($bsCaption, 0, 297) . '...';
            }
        }

        // Threads caption: 500 char limit — simple truncate
        $thCaption = mb_strlen($caption) > 500 ? mb_substr($caption, 0, 497) . '...' : $caption;

        $mediaItems = $request->input('media', []);
        $allMedia = [];
        $tempFiles = [];
        $storageFiles = []; // files copied to storage for signed URLs

        try {
            foreach ($mediaItems as $item) {
                $mediaType = $item['type'] ?? 'IMAGE';
                $mediaUrl = $item['url'] ?? null;

                if (! $mediaUrl) {
                    continue;
                }

                // Download media from Instagram CDN
                $tempFile = tempnam(sys_get_temp_dir(), 'xpost_');
                $tempFiles[] = $tempFile;

                $downloaded = Http::timeout(120)->withOptions(['sink' => $tempFile])->get($mediaUrl);
                if (! $downloaded->successful() || ! file_exists($tempFile) || filesize($tempFile) === 0) {
                    Log::warning('CrossPost: failed to download media', ['url' => $mediaUrl]);
                    continue;
                }

                $mimetype = mime_content_type($tempFile) ?: 'image/jpeg';

                if ($mediaType === 'VIDEO' && filesize($tempFile) > 50_000_000) {
                    Log::warning('CrossPost: video too large, skipping', ['size' => filesize($tempFile)]);
                    continue;
                }

                // Copy to storage so we can serve via signed URL (Threads API needs a reliable URL)
                $ext = match (true) {
                    str_starts_with($mimetype, 'video/') => 'mp4',
                    $mimetype === 'image/png' => 'png',
                    $mimetype === 'image/webp' => 'webp',
                    default => 'jpg',
                };
                $filename = 'xpost_' . uniqid() . '.' . $ext;
                copy($tempFile, storage_path("app/private/media/{$filename}"));
                $storageFiles[] = $filename;

                $signedUrl = URL::temporarySignedRoute('media.show', now()->addHours(2), ['filename' => $filename]);

                $allMedia[] = [
                    'url' => $mediaUrl,
                    'signed_url' => $signedUrl,
                    'mimetype' => $mimetype,
                    'title' => '',
                ];
            }

            // Bluesky: max 4 images, or 1 video (no mix) — uses Instagram CDN URLs
            $hasVideo = collect($allMedia)->contains(fn ($m) => str_starts_with($m['mimetype'], 'video/'));
            if ($hasVideo) {
                $blueskyMedia = [collect($allMedia)->first(fn ($m) => str_starts_with($m['mimetype'], 'video/'))];
            } else {
                $blueskyMedia = array_slice($allMedia, 0, 4);
            }

            // Threads: use signed URLs served from our server (Threads API can't always fetch Instagram CDN)
            $threadsMedia = array_map(fn ($m) => [
                'url' => $m['signed_url'],
                'mimetype' => $m['mimetype'],
                'title' => $m['title'],
            ], $allMedia);

            // --- Publish to Bluesky ---
            $bsResult = null;
            if ($doBluesky) {
                Log::info('CrossPost: publishing to Bluesky', [
                    'ig_id' => $igId,
                    'post_id' => $request->input('post_id'),
                    'media_count' => count($blueskyMedia),
                    'has_video' => $hasVideo,
                ]);

                $bsResult = (new BlueskyAdapter)->publish(
                    $bsAccount,
                    $bsCaption ?: "\u{1F4F8}",
                    ! empty($blueskyMedia) ? $blueskyMedia : null
                );

                Log::info('CrossPost: Bluesky result', [
                    'post_id' => $request->input('post_id'),
                    'success' => $bsResult['success'] ?? false,
                    'error' => $bsResult['error'] ?? null,
                ]);
            }

            // --- Publish to Threads ---
            $thResult = null;
            if ($doThreads) {
                Log::info('CrossPost: publishing to Threads', [
                    'ig_id' => $igId,
                    'post_id' => $request->input('post_id'),
                    'media_count' => count($threadsMedia),
                ]);

                $thResult = (new ThreadsAdapter)->publish(
                    $threadsAccount,
                    $thCaption ?: "\u{1F4F8}",
                    ! empty($threadsMedia) ? $threadsMedia : null
                );

                Log::info('CrossPost: Threads result', [
                    'post_id' => $request->input('post_id'),
                    'success' => $thResult['success'] ?? false,
                    'error' => $thResult['error'] ?? null,
                ]);
            }

            // Combine results (skipped platforms count as OK)
            $bsOk = ! $doBluesky || ($bsResult['success'] ?? false);
            $thOk = ! $doThreads || ($thResult['success'] ?? false);
            $allSuccess = $bsOk && $thOk;

            $errors = [];
            if ($doBluesky && ! ($bsResult['success'] ?? false)) {
                $errors[] = 'Bluesky: ' . ($bsResult['error'] ?? 'erreur');
            }
            if ($doThreads && ! ($thResult['success'] ?? false)) {
                $errors[] = 'Threads: ' . ($thResult['error'] ?? 'erreur');
            }

            // Remember this post as done (persists 30 days)
            if ($allSuccess) {
                $postId = $request->input('post_id');
                $cacheKey = "crosspost_done_{$igId}";
                $doneIds = Cache::get($cacheKey, []);
                $doneIds[] = $postId;
                Cache::put($cacheKey, array_unique($doneIds), now()->addDays(30));
            }

            return response()->json([
                'success' => $allSuccess,
                'error' => implode(' | ', $errors) ?: null,
                'platforms' => [
                    'bluesky' => $bsResult
                        ? ['success' => $bsResult['success'] ?? false, 'error' => $bsResult['error'] ?? null]
                        : null,
                    'threads' => $thResult
                        ? ['success' => $thResult['success'] ?? false, 'error' => $thResult['error'] ?? null]
                        : null,
                ],
            ]);

        } finally {
            // Clean up temp files
            foreach ($tempFiles as $f) {
                if (file_exists($f)) {
                    @unlink($f);
                }
            }
            // Clean up storage files used for signed URLs
            foreach ($storageFiles as $filename) {
                Storage::disk('local')->delete("media/{$filename}");
            }
        }
    }
}
