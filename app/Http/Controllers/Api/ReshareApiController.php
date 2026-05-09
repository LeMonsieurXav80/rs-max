<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\ResharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReshareApiController extends Controller
{
    public function __construct(
        private ResharingService $resharingService,
    ) {}

    /**
     * POST /api/posts/{post}/reshare
     * Repartage une publication interne (Post déjà publié) vers un ou plusieurs comptes.
     *
     * Body :
     *   - accounts: int[]   (IDs des SocialAccount cibles)
     *   - mode: auto|native_repost|native_quote|link  (défaut: auto)
     *   - text: string|null (requis pour native_quote ; optionnel pour link ; ignoré pour native_repost)
     */
    public function fromPost(Request $request, Post $post): JsonResponse
    {
        $this->authorizeAccess($request, $post);

        $validated = $request->validate([
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'mode' => 'nullable|in:auto,native_repost,native_quote,link',
            'text' => 'nullable|string|max:10000',
        ]);

        $user = $request->user();
        $accountIds = $this->filterAuthorizedAccounts($user, $validated['accounts']);
        if (empty($accountIds)) {
            return response()->json(['error' => 'Aucun compte cible accessible.'], 422);
        }

        try {
            $shadow = $this->resharingService->reshareFromPost(
                $user,
                $post,
                $accountIds,
                $validated['mode'] ?? ResharingService::MODE_AUTO,
                $validated['text'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->formatResult($shadow), 201);
    }

    /**
     * POST /api/posts/reshare-url
     * Repartage à partir d'une URL externe (X/Bluesky/Threads), ex. un vieux tweet pas géré dans rs-max.
     */
    public function fromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:1024',
            'accounts' => 'required|array|min:1',
            'accounts.*' => 'integer|exists:social_accounts,id',
            'mode' => 'nullable|in:auto,native_repost,native_quote,link',
            'text' => 'nullable|string|max:10000',
        ]);

        $user = $request->user();
        $accountIds = $this->filterAuthorizedAccounts($user, $validated['accounts']);
        if (empty($accountIds)) {
            return response()->json(['error' => 'Aucun compte cible accessible.'], 422);
        }

        try {
            $shadow = $this->resharingService->reshareFromUrl(
                $user,
                $validated['url'],
                $accountIds,
                $validated['mode'] ?? ResharingService::MODE_AUTO,
                $validated['text'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->formatResult($shadow), 201);
    }

    private function authorizeAccess(Request $request, Post $post): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            abort(403, 'Accès refusé.');
        }
    }

    /**
     * Conserve uniquement les comptes accessibles par l'utilisateur (pivot social_account_user).
     *
     * @param  int[]  $accountIds
     * @return int[]
     */
    private function filterAuthorizedAccounts($user, array $accountIds): array
    {
        if ($user->isAdmin()) {
            return $accountIds;
        }

        return $user->activeSocialAccounts()
            ->whereIn('social_accounts.id', $accountIds)
            ->pluck('social_accounts.id')
            ->all();
    }

    private function formatResult(Post $shadow): array
    {
        $shadow->load('postPlatforms.platform', 'postPlatforms.socialAccount');

        return [
            'post_id' => $shadow->id,
            'reshare_mode' => $shadow->reshare_mode,
            'reshare_source_url' => $shadow->reshare_source_url,
            'status' => $shadow->status,
            'targets' => $shadow->postPlatforms->map(fn ($pp) => [
                'account_id' => $pp->social_account_id,
                'account_name' => $pp->socialAccount?->name,
                'platform' => $pp->platform?->slug,
                'status' => $pp->status,
                'external_id' => $pp->external_id,
                'platform_url' => $pp->platform_url,
                'error' => $pp->error_message,
            ]),
        ];
    }
}
