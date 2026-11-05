<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordPressSiteSansGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'manager']);
    }

    private function site(): WpSource
    {
        return WpSource::create([
            'name' => 'Site',
            'url' => 'https://exemple.test',
            'post_types' => ['post'],
            'is_active' => true,
        ]);
    }

    public function test_les_ecrans_du_site_restent_disponibles(): void
    {
        $manager = $this->manager();
        $site = $this->site();

        $this->actingAs($manager)->get(route('wordpress-sites.index'))->assertOk()->assertSee('Site');
        $this->actingAs($manager)->get(route('wordpress-sites.create'))->assertOk();
        $this->actingAs($manager)->get(route('wordpress-sites.edit', $site))->assertOk();
    }

    public function test_enregistrer_un_site_ne_touche_pas_a_sa_periodicite(): void
    {
        $site = $this->site();
        $site->update(['schedule_frequency' => 'monthly']);

        $this->actingAs($this->manager())->put(route('wordpress-sites.update', $site), [
            'name' => 'Renomme',
            'url' => 'https://exemple.test',
            'post_types' => ['post'],
        ])->assertRedirect(route('wordpress-sites.index'));

        $this->assertSame('Renomme', $site->fresh()->name);
        $this->assertSame('monthly', $site->fresh()->schedule_frequency);
    }

    public function test_la_generation_de_publications_nexiste_plus(): void
    {
        $manager = $this->manager();
        $site = $this->site();

        $this->actingAs($manager)->get("/wordpress-sites/{$site->id}/preview")->assertNotFound();
        $this->actingAs($manager)->post("/wordpress-sites/{$site->id}/generate-preview")->assertNotFound();
        $this->actingAs($manager)->post("/wordpress-sites/{$site->id}/confirm-publications")->assertNotFound();
    }
}
