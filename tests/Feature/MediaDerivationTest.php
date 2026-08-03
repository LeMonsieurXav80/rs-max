<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Services\Media\MediaDerivationService;
use App\Services\MediaPublicationTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filiation des images générées : une slide de carrousel garde le lien vers les
 * photos de la médiathèque qui l'ont composée, et publier la slide compte comme
 * un usage de ces photos.
 */
class MediaDerivationTest extends TestCase
{
    use RefreshDatabase;

    private function media(string $name, array $attrs = []): MediaFile
    {
        return MediaFile::create([
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
        ] + $attrs);
    }

    public function test_la_slide_est_liee_aux_photos_de_ses_slots_image(): void
    {
        $photo = $this->media('plage');
        $autre = $this->media('falaise');
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);

        $linked = app(MediaDerivationService::class)->linkSlide($slide, [
            'brick' => 'photo-title-bl',
            'data' => [
                'image' => '/media/plage.jpg',
                'fond' => '/media/falaise.jpg',
                'title' => 'Un titre sans image',
            ],
        ]);

        $this->assertCount(2, $linked);
        $this->assertEqualsCanonicalizing(
            [$photo->id, $autre->id],
            $slide->sources()->pluck('media_files.id')->all(),
        );
        $this->assertDatabaseHas('media_derivations', [
            'derived_media_file_id' => $slide->id,
            'source_media_file_id' => $photo->id,
            'slot' => 'image',
            'brick' => 'photo-title-bl',
            'match_method' => 'render',
        ]);
    }

    public function test_le_lien_est_idempotent_et_ignore_les_fichiers_inconnus(): void
    {
        $photo = $this->media('plage');
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);
        $service = app(MediaDerivationService::class);

        $slideData = ['brick' => 'photo-title-bl', 'data' => [
            'image' => '/media/plage.jpg',
            'autre' => '/media/inexistante.jpg',
        ]];

        $service->linkSlide($slide, $slideData);
        $service->linkSlide($slide, $slideData);

        $this->assertSame(1, $slide->sources()->count());
        $this->assertSame($photo->id, $slide->sources()->first()->id);
    }

    public function test_publier_une_slide_trace_aussi_ses_photos_sources(): void
    {
        $photo = $this->media('plage');
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);
        $slide->sources()->attach($photo->id, ['match_method' => 'render']);

        $tracked = app(MediaPublicationTracker::class)->track(
            media: [['url' => '/media/slide-1.jpg']],
            postId: null,
            threadSegmentId: null,
            postPlatformId: null,
            socialAccountId: null,
            externalUrl: 'https://example.com/p/1',
            context: 'carrousel',
        );

        // 1 ligne pour la slide + 1 pour sa photo source.
        $this->assertSame(2, $tracked);
        $this->assertDatabaseHas('media_publications', [
            'media_file_id' => $photo->id,
            'via_media_file_id' => $slide->id,
            'context' => 'carrousel',
        ]);
        $this->assertSame(1, $photo->fresh()->publication_count);
        $this->assertSame(1, $slide->fresh()->publication_count);
    }

    public function test_une_photo_employee_dans_plusieurs_slides_ne_compte_quune_fois_par_publication(): void
    {
        $photo = $this->media('plage');
        $slides = collect(['slide-1', 'slide-2'])->map(function (string $name) use ($photo) {
            $slide = $this->media($name, ['source' => 'studio', 'is_generated' => true]);
            $slide->sources()->attach($photo->id, ['match_method' => 'render']);

            return $slide;
        });

        app(MediaPublicationTracker::class)->track(
            $slides->map(fn (MediaFile $s) => ['url' => '/media/'.$s->filename])->all(),
        );

        $this->assertSame(1, $photo->fresh()->publication_count);
    }

    public function test_lapi_media_expose_la_filiation_et_le_marqueur_genere(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $photo = $this->media('plage');
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);
        $slide->sources()->attach($photo->id, ['slot' => 'image', 'brick' => 'photo-title-bl', 'match_method' => 'render']);

        $this->getJson("/api/media/{$slide->id}")
            ->assertOk()
            ->assertJsonPath('is_generated', true)
            ->assertJsonPath('derived_from.0.id', $photo->id)
            ->assertJsonPath('derived_from.0.slot', 'image')
            ->assertJsonPath('derivatives', []);

        $this->getJson("/api/media/{$photo->id}")
            ->assertOk()
            ->assertJsonPath('is_generated', false)
            ->assertJsonPath('derivatives.0.id', $slide->id)
            ->assertJsonPath('derived_from', []);
    }

    public function test_la_recherche_peut_exclure_les_images_generees(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $photo = $this->media('plage', ['folder_id' => $folder->id]);
        $slide = $this->media('slide-1', ['folder_id' => $folder->id, 'source' => 'studio', 'is_generated' => true]);

        $ids = fn (array $query) => collect($this->getJson('/api/media/search?'.http_build_query($query))
            ->assertOk()->json('results'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$photo->id, $slide->id], $ids(['folder' => 'public']));
        $this->assertSame([$photo->id], $ids(['folder' => 'public', 'generated' => 0]));
        $this->assertSame([$slide->id], $ids(['folder' => 'public', 'generated' => 1]));
    }
}
