<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Les pages de configuration des plateformes doivent répondre.
 *
 * `platforms/linkedin` et `platforms/pinterest` ont été routées vers des
 * méthodes de contrôleur qui n'existaient pas : les deux pages tombaient en 500
 * (« Call to undefined method PlatformController::linkedin() ») sans que rien ne
 * le détecte, puisque la route, elle, se résolvait très bien. Le 500 LinkedIn a
 * en plus bloqué la seule issue à un token expiré — la reconnexion du compte.
 *
 * On balaie donc toutes les routes `platforms.*` en GET plutôt que de nommer
 * les réseaux : un réseau ajouté demain est couvert sans qu'on y pense.
 */
class PlatformPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_toutes_les_pages_plateformes_repondent(): void
    {
        $user = User::factory()->create();

        $uris = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, $name) => str_starts_with($name, 'platforms.')
                && in_array('GET', $route->methods(), true)
                && ! str_contains($route->uri(), '{'))
            ->map(fn ($route) => $route->uri());

        $this->assertNotEmpty($uris, 'Aucune page plateforme trouvée — le balayage ne teste rien.');

        foreach ($uris as $name => $uri) {
            $response = $this->actingAs($user)->get("/{$uri}");

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "La page {$name} (/{$uri}) a répondu {$response->getStatusCode()} au lieu de 200."
            );
        }
    }
}
