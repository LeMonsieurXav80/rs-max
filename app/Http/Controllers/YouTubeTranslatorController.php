<?php

namespace App\Http\Controllers;

use App\Jobs\TranslateYouTubeVideoJob;
use App\Models\LanguageGroup;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\YtTranslation;
use App\Services\YouTube\YouTubeTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouTubeTranslatorController extends Controller
{
    public function __construct(
        private YouTubeTranslationService $translationService,
    ) {}

    /**
     * Main page: list YouTube accounts + language groups.
     */
    public function index()
    {
        $platform = Platform::where('slug', 'youtube')->first();

        $accounts = $platform
            ? SocialAccount::where('platform_id', $platform->id)
                ->whereNotNull('credentials')
                ->get(['id', 'name', 'platform_account_id', 'profile_picture_url'])
            : collect();

        $languageGroups = LanguageGroup::all();

        return view('yt-translator.index', [
            'accounts' => $accounts,
            'languageGroups' => $languageGroups,
            'availableLanguages' => YouTubeTranslationService::AVAILABLE_LANGUAGES,
            'languageNames' => YouTubeTranslationService::LANGUAGE_NAMES,
        ]);
    }

    /**
     * AJAX: List videos for a YouTube account.
     */
    public function videos(Request $request): JsonResponse
    {
        $account = SocialAccount::findOrFail($request->input('account_id'));
        $result = $this->translationService->listVideos($account, 50);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        // Add translation status for each video
        $videoIds = array_column($result['videos'], 'id');
        $statuses = $this->translationService->getTranslationStatus($account->id, $videoIds);

        foreach ($result['videos'] as &$video) {
            $video['translations'] = $statuses[$video['id']] ?? [];
        }

        return response()->json($result['videos']);
    }

    /**
     * AJAX: List available captions for a video.
     */
    public function captions(Request $request): JsonResponse
    {
        $account = SocialAccount::findOrFail($request->input('account_id'));
        $videoId = $request->input('video_id');

        $captions = $this->translationService->listCaptions($account, $videoId);

        return response()->json($captions);
    }

    /**
     * AJAX: Launch translation job for selected videos/languages/types.
     */
    public function translate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:social_accounts,id',
            'video_ids' => 'required|array|min:1',
            'video_ids.*' => 'required|string',
            'languages' => 'required|array|min:1',
            'languages.*' => 'required|string|max:10',
            'types' => 'required|array|min:1',
            'types.*' => 'required|in:title,description,subtitles',
            'source_language' => 'nullable|string|max:10',
        ]);

        $account = SocialAccount::findOrFail($validated['account_id']);
        $sourceLang = $validated['source_language'] ?? 'en';
        $jobCount = 0;

        foreach ($validated['video_ids'] as $videoId) {
            foreach ($validated['languages'] as $language) {
                TranslateYouTubeVideoJob::dispatch(
                    $account,
                    $videoId,
                    $language,
                    $validated['types'],
                    $sourceLang
                );
                $jobCount++;
            }
        }

        return response()->json([
            'message' => "{$jobCount} traduction(s) lancée(s)",
            'jobs' => $jobCount,
        ]);
    }

    /**
     * AJAX: Get translation status for specific videos.
     */
    public function status(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');
        $videoIds = $request->input('video_ids', []);

        $translations = YtTranslation::where('social_account_id', $accountId)
            ->whereIn('video_id', $videoIds)
            ->get()
            ->groupBy('video_id')
            ->map(function ($items) {
                return $items->map(function ($t) {
                    return [
                        'type' => $t->type,
                        'language' => $t->language,
                        'status' => $t->status,
                        'error' => $t->error_message,
                        'uploaded_at' => $t->uploaded_at?->format('d/m/Y H:i'),
                    ];
                });
            });

        return response()->json($translations);
    }

    // ─── Language Groups CRUD ───────────────────────────────────

    public function storeLanguageGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'languages' => 'required|array|min:1',
            'languages.*' => 'required|string|max:10',
        ]);

        $group = LanguageGroup::create($validated);

        return response()->json($group, 201);
    }

    public function updateLanguageGroup(Request $request, LanguageGroup $languageGroup): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'languages' => 'required|array|min:1',
            'languages.*' => 'required|string|max:10',
        ]);

        $languageGroup->update($validated);

        return response()->json($languageGroup);
    }

    public function destroyLanguageGroup(LanguageGroup $languageGroup): JsonResponse
    {
        $languageGroup->delete();

        return response()->json(['message' => 'Groupe supprimé']);
    }
}
