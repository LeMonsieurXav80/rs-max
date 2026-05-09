<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\ResharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReshareController extends Controller
{
    public function __construct(
        private ResharingService $resharingService,
    ) {}

    /**
     * Affiche le formulaire de repartage d'un post interne.
     */
    public function formPost(Request $request, Post $post): View
    {
        $this->authorize($request, $post);

        $post->load('postPlatforms.platform', 'postPlatforms.socialAccount');

        $user = $request->user();
        $accounts = $user->activeSocialAccounts()->with('platform')->get();
        $groups = $user->accountGroups()->with('socialAccounts')->orderBy('order')->get();

        return view('posts.reshare-from-post', [
            'post' => $post,
            'accounts' => $accounts,
            'groups' => $groups,
        ]);
    }

    /**
     * AJAX : repartage un post interne.
     */
    public function fromPost(Request $request, Post $post): JsonResponse
    {
        $this->authorize($request, $post);

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

        return response()->json($this->formatResult($shadow));
    }

    /**
     * Affiche le formulaire de repartage à partir d'une URL externe collée.
     */
    public function formUrl(Request $request): View
    {
        $user = $request->user();

        $accounts = $user->isAdmin()
            ? \App\Models\SocialAccount::with('platform')->where('active', true)->orderBy('name')->get()
            : $user->activeSocialAccounts()->with('platform')->get();

        $groups = $user->accountGroups()->with('socialAccounts')->orderBy('order')->get();

        return view('posts.reshare-url', [
            'accounts' => $accounts,
            'groups' => $groups,
        ]);
    }

    /**
     * Soumet le repartage d'une URL externe.
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

        return response()->json($this->formatResult($shadow));
    }

    private function authorize(Request $request, Post $post): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $post->user_id !== $user->id) {
            abort(403, 'Accès refusé.');
        }
    }

    /**
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
