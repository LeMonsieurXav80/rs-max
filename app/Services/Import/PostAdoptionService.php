<?php

namespace App\Services\Import;

use App\Models\ExternalPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Services\MediaPublicationTracker;
use App\Services\PartnerTagService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transforme des publications natives cochees en UNE publication RS-Max.
 *
 * Le resultat est un `Post` ordinaire avec un `post_platform` par reseau : rien
 * de parallele, pour que les stats, le tag partenaire, le repartage et le
 * comptage d'usage des photos fonctionnent sans traitement particulier.
 */
class PostAdoptionService
{
    public function __construct(
        private readonly ExternalMediaImporter $mediaImporter,
        private readonly PartnerTagService $partnerTags,
        private readonly MediaPublicationTracker $publicationTracker,
    ) {}

    /**
     * @param  Collection<int, ExternalPost>  $externalPosts  Une par reseau.
     * @param  int|null  $referenceId  Publication dont le texte fait foi.
     * @return array{post: Post, downloaded: int, reused: int, skipped_videos: int}
     */
    public function adopt(Collection $externalPosts, User $user, ?int $referenceId = null): array
    {
        $reference = $externalPosts->firstWhere('id', $referenceId)
            ?? $externalPosts->sortByDesc(fn (ExternalPost $p) => mb_strlen((string) $p->content))->first();

        // Les photos sont rapatriees UNE fois pour tout le lot : la meme photo
        // publiee sur quatre reseaux ne doit peser qu'un fichier.
        $mediaResult = $this->mediaImporter->import(
            $externalPosts->flatMap(fn (ExternalPost $p) => $p->mediaItems())->all()
        );

        $post = DB::transaction(function () use ($externalPosts, $user, $reference, $mediaResult) {
            $post = Post::create([
                'user_id' => $user->id,
                'content_fr' => $reference->content ?: '',
                // Chaque reseau garde son propre texte : c'est exactement ce que
                // `platform_contents` sert deja a stocker cote composer.
                'platform_contents' => $this->platformContents($externalPosts),
                'media' => $mediaResult['media'] ?: null,
                'status' => 'published',
                'source_type' => 'native',
                'published_at' => $externalPosts->min('published_at') ?: now(),
            ]);

            foreach ($externalPosts as $externalPost) {
                $postPlatform = PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $externalPost->social_account_id,
                    'platform_id' => $externalPost->platform_id,
                    'status' => 'published',
                    'external_id' => $externalPost->external_id,
                    'published_at' => $externalPost->published_at,
                ]);

                $externalPost->update([
                    'adopted_post_id' => $post->id,
                    'adopted_at' => now(),
                ]);

                // Compte l'usage des photos une fois par reseau, a la vraie date
                // de publication : les filtres « pas republier depuis X jours »
                // ignoraient jusqu'ici tout ce qui etait publie hors RS-Max.
                $this->publicationTracker->track(
                    media: $post->media,
                    postId: $post->id,
                    postPlatformId: $postPlatform->id,
                    socialAccountId: $externalPost->social_account_id,
                    externalUrl: $externalPost->post_url,
                    context: 'external_adoption',
                );
            }

            return $post;
        });

        // Herite des partenaires tagues sur les photos reconnues. Hors
        // transaction : le service relit les medias depuis la base.
        $this->partnerTags->syncPost($post);

        return [
            'post' => $post->fresh(),
            'downloaded' => $mediaResult['downloaded'],
            'reused' => $mediaResult['reused'],
            'skipped_videos' => $mediaResult['skipped_videos'],
        ];
    }

    /**
     * Textes par plateforme, en ne gardant que ceux qui disent quelque chose.
     *
     * @param  Collection<int, ExternalPost>  $externalPosts
     */
    private function platformContents(Collection $externalPosts): ?array
    {
        $contents = [];

        foreach ($externalPosts as $externalPost) {
            $slug = $externalPost->platform?->slug;

            if ($slug && $externalPost->content) {
                $contents[$slug] = $externalPost->content;
            }
        }

        return $contents ?: null;
    }
}
