<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\MediaPublication;
use App\Models\WpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Couvre la commande media:reconcile-wp end-to-end : pagination WP mockée,
 * download mocké, phash mocké (Process), rapprochement par distance, résolution
 * d'article via post_parent, écriture idempotente en --commit.
 */
class ReconcileWpMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PHASH = 'a1b2c3d4e5f60718';

    private function seedData(): array
    {
        $folder = MediaFolder::create(['name' => 'Public', 'slug' => 'public', 'is_private' => false]);
        $media = MediaFile::create([
            'folder_id' => $folder->id,
            'filename' => 'plage.jpg',
            'original_name' => 'plage.jpg',
            'mime_type' => 'image/jpeg',
            'intimacy_level' => 'public',
            'phash' => self::PHASH,
        ]);
        $source = WpSource::create(['name' => 'PDC', 'url' => 'https://pdc.example.com']);

        return [$source, $media];
    }

    private function fakeHttp(): void
    {
        Http::fake([
            '*/wp-json/wp/v2/media*' => Http::response([
                [
                    'id' => 5567,
                    'source_url' => 'https://pdc.example.com/wp-content/uploads/plage.jpg',
                    'media_details' => ['sizes' => ['medium' => ['source_url' => 'https://pdc.example.com/wp-content/uploads/plage-300x200.jpg']]],
                    'post' => 1284, // post_parent → résolution d'article directe
                    'mime_type' => 'image/jpeg',
                    'title' => ['rendered' => 'Plage'],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
            '*/wp-content/*' => Http::response('FAKE-IMAGE-BYTES', 200),
        ]);
    }

    private function fakePhash(string $phash): void
    {
        Process::fake(function ($process) use ($phash) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, '--selfcheck')) {
                return Process::result('ok');
            }
            // Dernier argument = chemin du manifeste (écrit par la commande avant l'appel).
            $parts = is_array($process->command) ? $process->command : explode(' ', $cmd);
            $manifest = end($parts);
            $paths = json_decode((string) file_get_contents($manifest), true) ?: [];
            $out = [];
            foreach ($paths as $p) {
                $out[$p] = $phash;
            }

            return Process::result(json_encode($out));
        });
    }

    public function test_dry_run_matche_sans_ecrire(): void
    {
        [$source, $media] = $this->seedData();
        $this->fakeHttp();
        $this->fakePhash(self::PHASH); // distance 0 → match parfait

        $this->artisan('media:reconcile-wp', ['site' => (string) $source->id, '--python' => PHP_BINARY])
            ->assertSuccessful();

        // Dry-run : rien écrit.
        $this->assertDatabaseCount('media_publications', 0);
        $this->assertSame(0, $media->fresh()->publication_count);
    }

    public function test_commit_ecrit_le_lien_phash_et_est_idempotent(): void
    {
        [$source, $media] = $this->seedData();
        $this->fakeHttp();
        $this->fakePhash(self::PHASH);

        $this->artisan('media:reconcile-wp', ['site' => (string) $source->id, '--python' => PHP_BINARY, '--commit' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('media_publications', [
            'media_file_id' => $media->id,
            'wp_source_id' => $source->id,
            'wp_attachment_id' => 5567,
            'wp_post_id' => 1284,
            'match_method' => 'phash',
            'match_confidence' => 100,
        ]);
        $this->assertSame(1, $media->fresh()->publication_count);

        // Deuxième passage : idempotent, pas de doublon ni de ré-incrément.
        $this->artisan('media:reconcile-wp', ['site' => (string) $source->id, '--python' => PHP_BINARY, '--commit' => true])
            ->assertSuccessful();
        $this->assertSame(1, MediaPublication::count());
        $this->assertSame(1, $media->fresh()->publication_count);
    }

    public function test_sans_match_sous_le_seuil_n_ecrit_rien(): void
    {
        [$source, $media] = $this->seedData();
        $this->fakeHttp();
        // phash très éloigné (tous bits opposés) → distance 64 > seuil.
        $this->fakePhash('5e4d3c2b1a09f8e7');

        $this->artisan('media:reconcile-wp', ['site' => (string) $source->id, '--python' => PHP_BINARY, '--commit' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('media_publications', 0);
    }
}
