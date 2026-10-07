<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publication manuelle d'UNE diffusion (`posts.publishOne`).
 *
 * Quand la normalisation des medias a quitte l'upload pour la publication, la
 * plateforme est devenue un parametre OBLIGATOIRE de `resolveMediaUrls()` : le
 * plafond a respecter depend du reseau vise. Huit des neuf appels ont ete mis a
 * jour ; celui de `publishOne` est reste a un seul argument et levait une
 * TypeError AVANT meme d'avoir choisi l'adaptateur :
 *
 *   Too few arguments to function PublishController::resolveMediaUrls(),
 *   1 passed [...] and exactly 2 expected
 *
 * Consequence pour l'utilisateur : chaque reseau tombait en erreur, sur tous
 * les reseaux a la fois, des que la publication portait un media — et le
 * message n'avait aucun rapport visible avec la cause.
 */
class PublishOneMediaResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function postAvecMedia(): PostPlatform
    {
        $platform = Platform::create([
            'slug' => 'facebook',
            'name' => 'Facebook',
            'auth_type' => 'oauth2',
        ]);

        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => '42',
            'name' => 'Page de test',
            'credentials' => ['access_token' => 'jeton-bidon'],
        ]);

        $user = User::factory()->create(['role' => 'manager']);
        $user->socialAccounts()->attach($account->id, ['is_active' => true]);

        $post = Post::create([
            'user_id' => $user->id,
            'content_fr' => 'Quelques decouvertes',
            'status' => 'draft',
            // Un carrousel : c'est le cas qui faisait tomber la resolution.
            'media' => array_map(fn (int $i) => [
                'url' => "/media/photo-{$i}.jpg",
                'mimetype' => 'image/jpeg',
                'size' => 120000,
            ], range(1, 9)),
        ]);

        $this->actingAs($user);

        return PostPlatform::create([
            'post_id' => $post->id,
            'platform_id' => $platform->id,
            'social_account_id' => $account->id,
            'status' => 'pending',
        ]);
    }

    /**
     * On ne teste pas que la publication REUSSIT — les credentials sont bidon,
     * l'adaptateur Facebook refusera. On teste qu'elle atteint l'adaptateur :
     * la trace « submitted » n'est ecrite qu'APRES la resolution des medias,
     * sa presence prouve donc que celle-ci est passee. Avant le correctif, la
     * TypeError survenait avant cette trace et renvoyait un 500.
     */
    public function test_publier_une_diffusion_avec_medias_atteint_l_adaptateur(): void
    {
        $postPlatform = $this->postAvecMedia();

        $response = $this->postJson(route('posts.publishOne', $postPlatform));

        // 200 (publie) ou 422 (le reseau refuse) sont deux issues normales ;
        // un 500 signifie que la chaine casse avant d'avoir rien tente.
        $this->assertNotSame(500, $response->status(), 'reponse: '.$response->content());

        $this->assertDatabaseHas('post_logs', [
            'post_platform_id' => $postPlatform->id,
            'action' => 'submitted',
        ]);

        $this->assertStringNotContainsString(
            'resolveMediaUrls',
            $response->content(),
            'la resolution des medias ne doit plus figurer dans les erreurs'
        );
    }
}
