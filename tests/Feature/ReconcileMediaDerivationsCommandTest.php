<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rattrapage rétroactif de la filiation des images générées (media:reconcile-derivations).
 * Le phash est mocké au niveau du Process : c'est la logique de rapprochement, de
 * marge d'ambiguïté et de report des publications qui est testée, pas imagehash.
 */
class ReconcileMediaDerivationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PHASH = 'a1b2c3d4e5f60718';

    // Un bit d'écart : distance 1, bien sous le seuil.
    private const PHASH_PROCHE = 'a1b2c3d4e5f60719';

    protected function setUp(): void
    {
        parent::setUp();
        // Disque en mémoire : la commande lit les fichiers sur disque, mais rien
        // ne doit atterrir dans storage/app pour autant.
        Storage::fake('local');
    }

    private function media(string $name, array $attrs = []): MediaFile
    {
        Storage::disk('local')->put('media/'.$name.'.jpg', 'FAKE-IMAGE-BYTES');

        return MediaFile::create([
            'filename' => $name.'.jpg',
            'original_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
        ] + $attrs);
    }

    /** Le helper Python répond toujours le même phash pour les fichiers demandés. */
    private function fakePhash(string $phash): void
    {
        Process::fake(function ($process) use ($phash) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, '--selfcheck')) {
                return Process::result('ok');
            }
            $parts = is_array($process->command) ? $process->command : explode(' ', $cmd);
            $manifest = end($parts);
            $paths = json_decode((string) file_get_contents($manifest), true) ?: [];

            return Process::result(json_encode(array_fill_keys($paths, $phash)));
        });
    }

    public function test_dry_run_ne_lie_rien(): void
    {
        $this->media('plage', ['phash' => self::PHASH]);
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);
        $this->fakePhash(self::PHASH_PROCHE);

        $this->artisan('media:reconcile-derivations', ['--python' => PHP_BINARY])->assertSuccessful();

        $this->assertDatabaseCount('media_derivations', 0);
        $this->assertSame(0, $slide->fresh()->sources()->count());
    }

    public function test_commit_lie_la_photo_et_reporte_les_publications_passees(): void
    {
        $photo = $this->media('plage', ['phash' => self::PHASH]);
        $slide = $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);

        // Publication déjà enregistrée pour la slide, AVANT la mise en place du lien.
        MediaPublication::create([
            'media_file_id' => $slide->id,
            'published_at' => now()->subDays(3),
            'context' => 'carrousel',
        ]);

        $this->fakePhash(self::PHASH_PROCHE);

        $this->artisan('media:reconcile-derivations', ['--python' => PHP_BINARY, '--commit' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('media_derivations', [
            'derived_media_file_id' => $slide->id,
            'source_media_file_id' => $photo->id,
            'match_method' => 'phash',
        ]);
        // L'usage passé de la slide compte désormais pour la photo.
        $this->assertDatabaseHas('media_publications', [
            'media_file_id' => $photo->id,
            'via_media_file_id' => $slide->id,
            'context' => 'carrousel',
        ]);
        $this->assertSame(1, $photo->fresh()->publication_count);

        // Relance : aucun doublon, aucun compteur regonflé.
        $this->artisan('media:reconcile-derivations', ['--python' => PHP_BINARY, '--commit' => true, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(1, $photo->fresh()->publication_count);
        $this->assertDatabaseCount('media_derivations', 1);
    }

    public function test_un_cas_ambigu_nest_jamais_ecrit(): void
    {
        // Deux photos au phash identique : impossible de trancher.
        $this->media('plage-a', ['phash' => self::PHASH]);
        $this->media('plage-b', ['phash' => self::PHASH]);
        $this->media('slide-1', ['source' => 'studio', 'is_generated' => true]);

        $this->fakePhash(self::PHASH_PROCHE);

        $this->artisan('media:reconcile-derivations', ['--python' => PHP_BINARY, '--commit' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('media_derivations', 0);
    }
}
