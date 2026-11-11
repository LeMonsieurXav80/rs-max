<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesPublishableMedia;
use App\Models\Post;
use App\Models\PostLog;
use App\Models\PostPlatform;
use App\Services\Adapters\AdapterFactory;
use App\Services\PublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishController extends Controller
{
    use ResolvesPublishableMedia;

    public function __construct(
        private PublishingService $publishingService,
    ) {}

    /**
     * Manually publish a single PostPlatform entry (AJAX).
     * Runs synchronously (no queue) for immediate feedback during testing.
     */
    /**
     * Qui peut agir sur CETTE diffusion ?
     *
     * L'auteur et l'admin, sans condition. Les autres (manager) seulement pour
     * RELANCER un reseau tombe en erreur : voir la publication d'un collegue
     * sans pouvoir rattraper son echec n'aurait servi a rien, mais ni relancer
     * un `pending` — ce serait publier son brouillon a sa place — ni toucher a
     * un `published`, dont la remise a zero effacerait l'external_id et donc
     * le lien vers la publication reellement en ligne.
     */
    private function peutAgirSurLaDiffusion($user, PostPlatform $postPlatform): bool
    {
        if ($user->isAdmin() || $postPlatform->post->user_id === $user->id) {
            return true;
        }

        return $user->seesAllContent() && $postPlatform->status === 'failed';
    }

    public function publishOne(Request $request, PostPlatform $postPlatform): JsonResponse
    {
        $user = $request->user();
        $postPlatform->load('socialAccount.platform', 'post.user');
        $post = $postPlatform->post;

        if (! $this->peutAgirSurLaDiffusion($user, $postPlatform)) {
            return response()->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        if (! in_array($postPlatform->status, ['pending', 'failed'])) {
            return response()->json([
                'success' => false,
                'error' => 'Ce post est déjà en cours de publication ou publié.',
            ], 422);
        }

        $account = $postPlatform->socialAccount;

        // Check if account is active for this user (per-user activation).
        // Qui voit tout relance aussi sur un compte qui n'est pas le sien :
        // sans cette exception, le droit de relance accorde juste au-dessus
        // serait refuse ici pour toute publication d'un collegue.
        $isActiveForUser = $user->seesAllContent()
            || $account->users()->where('user_id', $user->id)->where('social_account_user.is_active', true)->exists();
        if (! $isActiveForUser) {
            return response()->json([
                'success' => false,
                'error' => 'Ce compte est désactivé.',
            ], 422);
        }

        $platform = $account->platform;

        $adapter = AdapterFactory::forAccount($account);
        if (! $adapter) {
            return response()->json([
                'success' => false,
                'error' => "Pas d'adaptateur pour la plateforme : {$platform->slug}",
            ], 422);
        }

        if ($platform->slug === 'tiktok') {
            $queued = $this->queueTikTok($postPlatform);

            return response()->json(['success' => $queued, 'queued' => $queued,
                'message' => 'Publication TikTok mise en file sur le NAS.'], $queued ? 202 : 409);
        }

        // Build content (translations handled by PublishingService)
        $content = $this->publishingService->getContentForAccount($post, $account);

        // Resolve media URLs
        $media = $this->resolveMediaUrls($post->media, $platform->slug);

        // Mark as publishing
        $postPlatform->update(['status' => 'publishing']);
        $post->update(['status' => 'publishing']);

        PostLog::create([
            'post_platform_id' => $postPlatform->id,
            'action' => 'submitted',
            'details' => ['platform' => $platform->slug, 'account' => $account->name, 'manual' => true],
        ]);

        // Publish synchronously
        $options = ($this->buildOptions($post) ?? []) + ['publication_key' => 'post-platform:'.$postPlatform->id];
        $result = $adapter->publish($account, $content, $media, $options);

        if ($result['success']) {
            $postPlatform->update([
                'status' => 'published',
                'external_id' => $result['external_id'] ?? null,
                'platform_url' => $result['permalink'] ?? $postPlatform->platform_url,
                'published_at' => now(),
            ]);

            PostLog::create([
                'post_platform_id' => $postPlatform->id,
                'action' => 'published',
                'details' => [
                    'external_id' => $result['external_id'] ?? null,
                    'platform' => $platform->slug,
                    'account' => $account->name,
                ],
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
            'details' => [
                'error' => $result['error'] ?? 'Erreur inconnue',
                'platform' => $platform->slug,
                'account' => $account->name,
                'account_id' => $account->id,
                'post_id' => $post->id,
            ],
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

        if (! $user->isAdmin() && $post->user_id !== $user->id) {
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

        // La résolution des médias est faite DANS la boucle : pour un fichier
        // `preserve_original`, le fichier réellement envoyé dépend du réseau
        // (cf `MediaVariantService`). La sortir d'ici enverrait à tous la
        // variante calculée pour le premier.
        $post->update(['status' => 'publishing']);
        $options = $this->buildOptions($post);

        $results = [];

        foreach ($postPlatforms as $pp) {
            $account = $pp->socialAccount;
            $platform = $account->platform;
            $adapter = AdapterFactory::forAccount($account);

            if (! $adapter) {
                $results[] = ['account' => $account->name, 'success' => false, 'error' => 'Pas d\'adaptateur'];

                continue;
            }

            if ($platform->slug === 'tiktok') {
                $queued = $this->queueTikTok($pp);
                $results[] = ['account' => $account->name, 'success' => $queued, 'queued' => $queued];

                continue;
            }

            $content = $this->publishingService->getContentForAccount($post, $account);
            $media = $this->resolveMediaUrls($post->media, $platform->slug);

            $pp->update(['status' => 'publishing']);

            PostLog::create([
                'post_platform_id' => $pp->id,
                'action' => 'submitted',
                'details' => ['platform' => $platform->slug, 'account' => $account->name, 'manual' => true],
            ]);

            $result = $adapter->publish($account, $content, $media, ($options ?? []) + ['publication_key' => 'post-platform:'.$pp->id]);

            if ($result['success']) {
                $pp->update([
                    'status' => 'published',
                    'external_id' => $result['external_id'] ?? null,
                    'platform_url' => $result['permalink'] ?? $pp->platform_url,
                    'published_at' => now(),
                ]);

                PostLog::create([
                    'post_platform_id' => $pp->id,
                    'action' => 'published',
                    'details' => [
                        'external_id' => $result['external_id'] ?? null,
                        'platform' => $platform->slug,
                        'account' => $account->name,
                    ],
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
                    'details' => [
                        'error' => $result['error'] ?? 'Erreur inconnue',
                        'platform' => $platform->slug,
                        'account' => $account->name,
                        'account_id' => $account->id,
                        'post_id' => $post->id,
                    ],
                ]);

                $results[] = ['account' => $account->name, 'success' => false, 'error' => $result['error'] ?? 'Erreur inconnue'];
            }
        }

        $this->updatePostStatus($post);

        $queuedCount = collect($results)->where('queued', true)->count();
        $successCount = collect($results)->where('success', true)->count();
        $totalCount = count($results);

        return response()->json([
            'success' => $successCount > 0,
            'message' => $queuedCount ? "{$queuedCount} publication(s) TikTok mise(s) en file sur le NAS." : "{$successCount}/{$totalCount} publication(s) réussie(s).",
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

        if (! $this->peutAgirSurLaDiffusion($user, $postPlatform)) {
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

    private function buildOptions(Post $post): ?array
    {
        $options = [];

        if ($post->location_id) {
            $options['location_id'] = $post->location_id;
            $options['location_name'] = $post->location_name;
        }

        return ! empty($options) ? $options : null;
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

    private function queueTikTok(PostPlatform $delivery): bool
    {
        $claimed = PostPlatform::whereKey($delivery->id)->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'publishing', 'error_message' => null]);
        if (! $claimed) {
            return false;
        }

        $delivery->post->update(['status' => 'publishing']);
        \App\Jobs\PublishToPlatformJob::dispatch($delivery);

        return true;
    }
}
