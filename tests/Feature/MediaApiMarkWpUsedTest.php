<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaPublication;
use App\Models\User;
use App\Models\WpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Couvre POST /api/media/{id}/mark-wp-used (tracking d'usage WordPress par-site,
 * idempotent) et le filtre d'usage (used / used_on / unused_on) de GET /api/media/search.
 */
class MediaApiMarkWpUsedTest extends TestCase
{
    use RefreshDatabase;

    private function media(MediaFolder $folder, string $name): MediaFile
    {
        return MediaFile::create([
            'folder_id' => $folder->id,
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
        ]);
    }

    private function wpSource(string $name): WpSource
    {
        return WpSource::create([
            'name' => $name,
            'url' => 'https://'.$name.'.example.com',
        ]);
    }

    public function test_mark_wp_used_cree_une_publication_et_incremente_le_compteur(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'plage');
        $site = $this->wpSource('pdc');

        $this->postJson("/api/media/{$media->id}/mark-wp-used", [
            'wp_source_id' => $site->id,
            'wp_post_id' => 1284,
            'wp_attachment_id' => 5567,
            'wp_url' => 'https://pdc.example.com/plage.jpg',
            'context' => 'article:plages-algarve',
        ])->assertStatus(201)->assertJson([
            'media_file_id' => $media->id,
            'wp_source_id' => $site->id,
            'wp_post_id' => 1284,
            'wp_attachment_id' => 5567,
            'created' => true,
            'publication_count' => 1,
        ]);

        $this->assertDatabaseHas('media_publications', [
            'media_file_id' => $media->id,
            'wp_source_id' => $site->id,
            'wp_attachment_id' => 5567,
            'match_method' => 'manual',
            'match_confidence' => 100,
            'external_url' => 'https://pdc.example.com/plage.jpg',
        ]);
        $this->assertSame(1, $media->fresh()->publication_count);
    }

    public function test_mark_wp_used_est_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'idem');
        $site = $this->wpSource('vantour');

        $payload = [
            'wp_source_id' => $site->id,
            'wp_post_id' => 42,
            'wp_attachment_id' => 999,
        ];

        $first = $this->postJson("/api/media/{$media->id}/mark-wp-used", $payload)->assertStatus(201);
        $publishedAt = $first->json('published_at');

        // Deuxième appel identique : pas de doublon, 200, created=false, compteur figé.
        $this->postJson("/api/media/{$media->id}/mark-wp-used", $payload)
            ->assertStatus(200)
            ->assertJson([
                'created' => false,
                'publication_count' => 1,
                'published_at' => $publishedAt, // figé à la première trace
            ]);

        $this->assertSame(1, MediaPublication::where('media_file_id', $media->id)->count());
        $this->assertSame(1, $media->fresh()->publication_count);
    }

    public function test_mark_wp_used_meme_image_deux_sites_donne_deux_lignes(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'partagee');
        $pdc = $this->wpSource('pdc');
        $vantour = $this->wpSource('vantour');

        $this->postJson("/api/media/{$media->id}/mark-wp-used", [
            'wp_source_id' => $pdc->id, 'wp_post_id' => 1, 'wp_attachment_id' => 10,
        ])->assertStatus(201);
        $this->postJson("/api/media/{$media->id}/mark-wp-used", [
            'wp_source_id' => $vantour->id, 'wp_post_id' => 2, 'wp_attachment_id' => 20,
        ])->assertStatus(201);

        $this->assertSame(2, MediaPublication::where('media_file_id', $media->id)->count());
        $this->assertSame(2, $media->fresh()->publication_count);
    }

    public function test_mark_wp_used_valide_les_champs_requis(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = $this->media($folder, 'invalide');

        // wp_source_id manquant + inexistant
        $this->postJson("/api/media/{$media->id}/mark-wp-used", [
            'wp_post_id' => 1, 'wp_attachment_id' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('wp_source_id');

        $site = $this->wpSource('pdc');
        // wp_attachment_id manquant
        $this->postJson("/api/media/{$media->id}/mark-wp-used", [
            'wp_source_id' => $site->id, 'wp_post_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('wp_attachment_id');
    }

    public function test_filtre_used_global(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $used = $this->media($folder, 'deja-publiee');
        $unused = $this->media($folder, 'jamais-publiee');
        $site = $this->wpSource('pdc');

        MediaPublication::create([
            'media_file_id' => $used->id,
            'wp_source_id' => $site->id,
            'wp_post_id' => 1,
            'wp_attachment_id' => 5,
            'published_at' => now(),
        ]);

        // used=1 → seulement la photo publiée
        $ids = collect($this->getJson('/api/media/search?folder=public&used=1')->json('results'))->pluck('id');
        $this->assertContains($used->id, $ids->all());
        $this->assertNotContains($unused->id, $ids->all());

        // used=0 → seulement la photo jamais publiée
        $ids = collect($this->getJson('/api/media/search?folder=public&used=0')->json('results'))->pluck('id');
        $this->assertContains($unused->id, $ids->all());
        $this->assertNotContains($used->id, $ids->all());
    }

    public function test_filtre_used_on_et_unused_on_par_site(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $onPdc = $this->media($folder, 'sur-pdc');
        $onVantour = $this->media($folder, 'sur-vantour');
        $pdc = $this->wpSource('pdc');
        $vantour = $this->wpSource('vantour');

        MediaPublication::create([
            'media_file_id' => $onPdc->id, 'wp_source_id' => $pdc->id,
            'wp_post_id' => 1, 'wp_attachment_id' => 5, 'published_at' => now(),
        ]);
        MediaPublication::create([
            'media_file_id' => $onVantour->id, 'wp_source_id' => $vantour->id,
            'wp_post_id' => 2, 'wp_attachment_id' => 6, 'published_at' => now(),
        ]);

        // Publiée sur PDC : apparaît dans used_on=pdc, pas dans used_on=vantour.
        $usedOnPdc = collect($this->getJson("/api/media/search?folder=public&used_on={$pdc->id}")->json('results'))->pluck('id');
        $this->assertContains($onPdc->id, $usedOnPdc->all());
        $this->assertNotContains($onVantour->id, $usedOnPdc->all());

        // unused_on=pdc : celle publiée seulement sur Vantour est candidate pour PDC.
        $unusedOnPdc = collect($this->getJson("/api/media/search?folder=public&unused_on={$pdc->id}")->json('results'))->pluck('id');
        $this->assertContains($onVantour->id, $unusedOnPdc->all());
        $this->assertNotContains($onPdc->id, $unusedOnPdc->all());
    }
}
