<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\FacebookAdapter;
use App\Services\Adapters\InstagramAdapter;
use App\Services\Adapters\PlatformAdapterInterface;
use App\Services\Adapters\TelegramAdapter;
use App\Services\Adapters\ThreadsAdapter;
use App\Services\Adapters\TwitterAdapter;
use App\Services\PublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class PublishController extends Controller
{
    public function __construct(
        private PublishingService $publishingService,
    ) {}

    /**
     * Manually publish a single PostPlatform entry (AJAX).
     * Runs synchronously (no queue) for immediate feedback during testing.
     */
    public function publishOne(Request $request, PostPlatform $postPlatform): JsonResponse
    {
        $user = $request->user();
        $postPlatform->load('socialAccount.platform', 'post.user');
        $post = $postPlatform->post;

        if (! $user->is_admin && $post->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        if (! in_array($postPlatform->status, ['pending', 'failed'])) {
            return response()->json([
                'success' => false,
                'error' => 'Ce post est déjà en cours de publication ou publié.',
            ], 422);
        }

        $account = $postPlatform->socialAccount;

        // Check if account is active for this user (per-user activation)
        $isActiveForUser = $account->users()->where('user_id', $user->id)->where('social_account_user.is_active', true)->exists();
        if (! $isActiveForUser) {
            return response()->json([
                'success' => false,
                'error' => 'Ce compte est désactivé.',
            ], 422);
        }

        $platform = $account->platform;

        $adapter = $this->getAdapter($platform->slug);
        if (! $adapter) {
            return response()->json([
                'success' => false,
                'error' => "Pas d'adaptateur pour la plateforme : {$platform->slug}",
            ], 422);
        }

        // Build content (translations handled by PublishingService)
        $content = $this->publishingService->getContentForAccount($post, $account);

        // Resolve media URLs
        $media = $this->resolveMediaUrls($post->media);

        // Mark as publishing
        $postPlatform->update(['status' => 'publishing']);
        $post->update(['status' => 'publishing']);

        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'submitted',
            'details' => ['platform' => $platform->slug, 'account' => $account->name, 'manual' => true],
        ]);

        // Publish synchronously
        $options = $this->buildOptions($post);
        $result = $adapter->publish($account, $content, $media, $options);

        if ($result['success']) {
            $postPlatform->update([
                'status' => 'published',
                'external_id' => $result['external_id'] ?? null,
                'published_at' => now(),
            ]);

            PostLog::create([
                'post_platform_id' => $postPlatform->id,
                'action' => 'published',
                'details' => ['external_id' => $result['external_id'] ?? null],
            ]);

            $this->updatePostStatus($post);

            return response()->json([
                'success' => true,
                'external_id' => $result['external_id'] ?? null,
                'message' => "Publié sur {$account->name} avec succès.",
            ]);
        }

        $postPlatform->update([
            'status' => 'failed',
            'error_message' => $result['error'] ?? 'Erreur inconnue',
        ]);

        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'failed',
            'details' => ['error' => $result['error'] ?? 'Erreur inconnue'],
        ]);

        $this->updatePostStatus($post);

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Erreur inconnue',
        ], 422);
    }

    /**
     * Manually publish all pending/failed PostPlatforms of a post (AJAX).
     */
    public function publishAll(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_admin && $post->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $userId = $user->id;
        $postPlatforms = $post->postPlatforms()
            ->with('socialAccount.platform')
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('socialAccount', function ($q) use ($userId) {
                $q->whereHas('users', fn ($uq) => $uq->where('social_account_user.user_id', $userId)->where('social_account_user.is_active', true));
            })
            ->get();

        if ($postPlatforms->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'Aucune plateforme en attente de publication.',
            ], 422);
        }

        $media = $this->resolveMediaUrls($post->media);
        $post->update(['status' => 'publishing']);
        $options = $this->buildOptions($post);

        $results = [];

        foreach ($postPlatforms as $pp) {
            $account = $pp->socialAccount;
            $platform = $account->platform;
            $adapter = $this->getAdapter($platform->slug);

            if (! $adapter) {
                $results[] = ['account' => $account->name, 'success' => false, 'error' => 'Pas d\'adaptateur'];
                continue;
            }

            $content = $this->publishingService->getContentForAccount($post, $account);

            $pp->update(['status' => 'publishing']);

            PostLog::create([
                'post_platform_id' => $pp->id,
                'action' => 'submitted',
                'details' => ['platform' => $platform->slug, 'account' => $account->name, 'manual' => true],
            ]);

            $result = $adapter->publish($account, $content, $media, $options);

            if ($result['success']) {
                $pp->update([
                    'status' => 'published',
                    'external_id' => $result['external_id'] ?? null,
                    'published_at' => now(),
                ]);

                PostLog::create([
                    'post_platform_id' => $pp->id,
                    'action' => 'published',
                    'details' => ['external_id' => $result['external_id'] ?? null],
                ]);

                $results[] = ['account' => $account->name, 'success' => true, 'external_id' => $result['external_id'] ?? null];
            } else {
                $pp->update([
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Erreur inconnue',
                ]);

                PostLog::create([
                    'post_platform_id' => $pp->id,
                    'action' => 'failed',
                    'details' => ['error' => $result['error'] ?? 'Erreur inconnue'],
                ]);

                $results[] = ['account' => $account->name, 'success' => false, 'error' => $result['error'] ?? 'Erreur inconnue'];
            }
        }

        $this->updatePostStatus($post);

        $successCount = collect($results)->where('success', true)->count();
        $totalCount = count($results);

        return response()->json([
            'success' => $successCount > 0,
            'message' => "{$successCount}/{$totalCount} publication(s) réussie(s).",
            'results' => $results,
        ]);
    }

    /**
     * Reset a failed/published PostPlatform back to pending (AJAX).
     */
    public function resetOne(Request $request, PostPlatform $postPlatform): JsonResponse
    {
        $user = $request->user();
        $postPlatform->load('post');

        if (! $user->is_admin && $postPlatform->post->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $postPlatform->update([
            'status' => 'pending',
            'external_id' => null,
            'error_message' => null,
            'published_at' => null,
        ]);

        // Reset post status if it was published/failed
        $post = $postPlatform->post;
        if (in_array($post->status, ['published', 'failed'])) {
            $post->update(['status' => 'scheduled', 'published_at' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Remis en attente.']);
    }

    private function getAdapter(string $slug): ?PlatformAdapterInterface
    {
        return match ($slug) {
            'telegram' => new TelegramAdapter(),
            'facebook' => new FacebookAdapter(),
            'instagram' => new InstagramAdapter(),
            'threads' => new ThreadsAdapter(),
            'twitter' => new TwitterAdapter(),
            default => null,
        };
    }

    private function buildOptions(Post $post): ?array
    {
        $options = [];

        if ($post->location_id) {
            $options['location_id'] = $post->location_id;
            $options['location_name'] = $post->location_name;
        }

        return ! empty($options) ? $options : null;
    }

    private function resolveMediaUrls(?array $media): ?array
    {
        if (empty($media)) {
            return $media;
        }

        return array_map(function ($item) {
            $url = $item['url'] ?? '';

            if (str_starts_with($url, '/media/')) {
                $filename = basename($url);
                $item['url'] = URL::temporarySignedRoute(
                    'media.show',
                    now()->addHours(4),
                    ['filename' => $filename]
                );
            }

            return $item;
        }, $media);
    }

    private function updatePostStatus(Post $post): void
    {
        $post->refresh();
        $statuses = $post->postPlatforms->pluck('status');

        if ($statuses->every(fn ($s) => $s === 'published')) {
            $post->update(['status' => 'published', 'published_at' => now()]);
        } elseif ($statuses->every(fn ($s) => in_array($s, ['published', 'failed']))) {
            $hasPublished = $statuses->contains('published');
            $post->update([
                'status' => $hasPublished ? 'published' : 'failed',
                'published_at' => $hasPublished ? now() : null,
            ]);
        }
    }
}
