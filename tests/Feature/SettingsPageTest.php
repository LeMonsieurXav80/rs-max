<?php

namespace Tests\Feature;

use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La page Paramètres agrège tous les onglets dans un seul formulaire et une seule
 * vue : une directive Blade mal formée dans un onglet casse la page entière, en
 * 500, sans que rien ne le signale à la compilation (`view:cache` passe).
 * Ce test est le garde-fou : il rend la page pour de vrai.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_la_page_parametres_se_rend_entierement(): void
    {
        MediaFolder::create(['name' => 'Visuels', 'slug' => 'visuels']);

        $this->actingAs($this->manager())
            ->get(route('settings.index'))
            ->assertOk()
            // Le dernier onglet du fichier : s'il est là, rien n'a été avalé en route.
            ->assertSee('images de stock libres de droits')
            ->assertSee('Dossier des images générées')
            ->assertSee('Visuels');
    }

    public function test_lia_gratuite_a_disparu(): void
    {
        $this->actingAs($this->manager())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee('IA Gratuite')
            ->assertDontSee('Groq');
    }

    public function test_un_utilisateur_simple_na_pas_acces(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('settings.index'))
            ->assertForbidden();
    }
}
