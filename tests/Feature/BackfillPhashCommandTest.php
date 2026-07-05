<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Couvre media:backfill-phash : calcul du phash des images sans phash depuis le
 * disque local, dry-run vs --commit, comptage des fichiers manquants.
 */
class BackfillPhashCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PHASH = 'a1b2c3d4e5f60718';

    private function image(string $name, bool $onDisk = true): MediaFile
    {
        $folder = MediaFolder::firstOrCreate(['slug' => 'public'], ['name' => 'Public', 'is_private' => false]);
        if ($onDisk) {
            Storage::disk('local')->put("media/{$name}", 'FAKE-IMAGE-BYTES');
        }

        return MediaFile::create([
            'folder_id' => $folder->id,
            'filename' => $name,
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
        ]);
    }

    private function fakePhash(): void
    {
        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, '--selfcheck')) {
                return Process::result('ok');
            }
            $parts = is_array($process->command) ? $process->command : explode(' ', $cmd);
            $manifest = end($parts);
            $paths = json_decode((string) file_get_contents($manifest), true) ?: [];
            $out = [];
            foreach ($paths as $p) {
                $out[$p] = self::PHASH;
            }

            return Process::result(json_encode($out));
        });
    }

    public function test_dry_run_ne_touche_pas_la_base(): void
    {
        Storage::fake('local');
        $this->fakePhash();
        $img = $this->image('plage.jpg');

        $this->artisan('media:backfill-phash', ['--python' => PHP_BINARY])->assertSuccessful();

        $this->assertNull($img->fresh()->phash);
    }

    public function test_commit_ecrit_le_phash(): void
    {
        Storage::fake('local');
        $this->fakePhash();
        $img = $this->image('plage.jpg');

        $this->artisan('media:backfill-phash', ['--python' => PHP_BINARY, '--commit' => true])->assertSuccessful();

        $this->assertSame(self::PHASH, $img->fresh()->phash);
    }

    public function test_ignore_les_images_deja_hashees_sauf_force(): void
    {
        Storage::fake('local');
        $this->fakePhash();
        $img = $this->image('deja.jpg');
        $img->update(['phash' => 'ffffffffffffffff']);

        // Sans --force : l'image déjà hashée n'est pas retouchée.
        $this->artisan('media:backfill-phash', ['--python' => PHP_BINARY, '--commit' => true])->assertSuccessful();
        $this->assertSame('ffffffffffffffff', $img->fresh()->phash);

        // Avec --force : recalcul.
        $this->artisan('media:backfill-phash', ['--python' => PHP_BINARY, '--commit' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(self::PHASH, $img->fresh()->phash);
    }

    public function test_ignore_les_fichiers_absents_du_disque(): void
    {
        Storage::fake('local');
        $this->fakePhash();
        $present = $this->image('present.jpg', onDisk: true);
        $absent = $this->image('absent.jpg', onDisk: false);

        $this->artisan('media:backfill-phash', ['--python' => PHP_BINARY, '--commit' => true])->assertSuccessful();

        // Seule l'image présente sur disque est hashée ; l'absente reste sans phash.
        $this->assertSame(self::PHASH, $present->fresh()->phash);
        $this->assertNull($absent->fresh()->phash);
    }
}
