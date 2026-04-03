<?php

namespace App\Http\Controllers;

use App\Models\Hashtag;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BulkPostController extends Controller
{
    /**
     * Show the bulk post creation wizard.
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

        return view('posts.bulk', compact('accounts', 'accountGroups', 'defaultAccountIds'));
    }

    /**
     * Save (create or update) a single row from the bulk editor.
     */
    public function saveRow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id'       => 'nullable|integer|exists:posts,id',
            'content_fr'    => 'nullable|string|max:10000',
            'hashtags'      => 'nullable|string|max:1000',
            'media'         => 'nullable|array',
            'media.*'       => 'nullable|string|max:5000',
            'scheduled_at'  => 'nullable|date',
            'accounts'      => 'required|array|min:1',
            'accounts.*'    => 'integer|exists:social_accounts,id',
        ]);

        $user = $request->user();

        // Verify all accounts are accessible and active for this user
        $accountIds = $validated['accounts'];
        $validAccounts = $user->activeSocialAccounts()
            ->whereIn('social_accounts.id', $accountIds)
            ->get();

        if ($validAccounts->count() !== count($accountIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Un ou plusieurs comptes sélectionnés sont invalides.',
            ], 422);
        }

        // Decode media JSON strings
        $media = null;
        if (! empty($validated['media'])) {
            $media = array_values(array_filter(array_map(function ($item) {
                $decoded = is_string($item) ? json_decode($item, true) : $item;

                return is_array($decoded) && isset($decoded['url']) ? $decoded : null;
            }, $validated['media'])));
            if (empty($media)) {
                $media = null;
            }
        }

        $postId = $validated['post_id'] ?? null;

        $post = DB::transaction(function () use ($validated, $user, $validAccounts, $media, $postId) {
            if ($postId) {
                // Update existing post
                $post = Post::where('id', $postId)
                    ->where('user_id', $user->id)
                    ->firstOrFail();

                $post->update([
                    'content_fr'    => $validated['content_fr'] ?? '',
                    'hashtags'      => $validated['hashtags'] ?? null,
                    'media'         => $media,
                    'scheduled_at'  => $validated['scheduled_at'] ?? null,
                    'translations'  => null,
                ]);

                // Re-sync PostPlatform entries
                $post->postPlatforms()->delete();
            } else {
                // Create new post
                $post = Post::create([
                    'user_id'       => $user->id,
                    'content_fr'    => $validated['content_fr'] ?? '',
                    'hashtags'      => $validated['hashtags'] ?? null,
                    'media'         => $media,
                    'status'        => 'scheduled',
                    'scheduled_at'  => $validated['scheduled_at'] ?? null,
                    'auto_translate' => true,
                ]);
            }

            foreach ($validAccounts as $account) {
                PostPlatform::create([
                    'post_id'           => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id'       => $account->platform_id,
                    'status'            => 'pending',
                ]);
            }

            return $post;
        });

        // Record hashtag usage
        if (! empty($validated['hashtags'])) {
            $hashtags = preg_split('/[\s,]+/', $validated['hashtags'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($hashtags as $tag) {
                Hashtag::recordUsage($user->id, $tag);
            }
        }

        return response()->json([
            'success' => true,
            'post_id' => $post->id,
        ]);
    }

    /**
     * Delete a row (post) from the bulk editor.
     */
    public function deleteRow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => 'required|integer|exists:posts,id',
        ]);

        $user = $request->user();
        $post = Post::where('id', $validated['post_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (in_array($post->status, ['publishing', 'published'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un post en cours de publication ou déjà publié.',
            ], 422);
        }

        DB::transaction(function () use ($post) {
            foreach ($post->postPlatforms as $pp) {
                $pp->logs()->delete();
            }
            $post->postPlatforms()->delete();
            $post->delete();
        });

        return response()->json(['success' => true]);
    }
}
