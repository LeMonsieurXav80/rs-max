<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Models\MediaPublication;
use App\Models\WpSource;
use App\Services\Media\PhashMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Réconciliation rétroactive des images publiées sur un site WordPress AVANT la
 * mise en place du tracking, quand le rapprochement par nom de fichier est
 * impossible (WP compresse + renomme). On matche par phash perceptuel : le phash
 * des images WP est recalculé avec EXACTEMENT le même algo que l'ingest Mac
 * (imagehash.phash via le venv du pipeline — voir scripts/wp_phash.py), puis
 * comparé au catalogue par distance de Hamming.
 *
 * Best-effort : dry-run par défaut, n'écrit qu'avec --commit, et n'écrit jamais
 * les cas ambigus (≥ 2 candidats sous le seuil) ni les sans-match.
 */
class ReconcileWpMediaCommand extends Command
{
    protected $signature = 'media:reconcile-wp
        {site : Slug (ex. pdc|vantour) ou id numérique de la ligne wp_sources}
        {--threshold=10 : Distance de Hamming max pour accepter un match}
        {--wp-source-id= : Force l\'id wp_sources (désambiguïse si le nom matche plusieurs lignes, ex. Vantour EN/DE)}
        {--limit= : Limite le nombre d\'attachments scannés (debug)}
        {--python= : Binaire Python à utiliser (défaut: config services.media_reconcile.python)}
        {--commit : Écrit réellement les liens (par défaut: dry-run, aucune écriture)}';

    protected $description = 'Réconcilie rétroactivement les images d\'un site WordPress avec le catalogue média par distance de phash (best-effort, dry-run par défaut).';

    /** @var array<int, array{id:int, phash:string}> Catalogue en mémoire (phash 16-hex valides). */
    private array $catalog = [];

    /** @var list<array{id:int, featured_media:int, content:string, link:string}>|null Posts publiés (chargés à la demande). */
    private ?array $posts = null;

    private string $baseUrl = '';

    private ?string $authUser = null;

    private ?string $authPass = null;

    private string $userAgent = '';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');
        $commit = (bool) $this->option('commit');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $source = $this->resolveSource();
        if (! $source) {
            return self::FAILURE;
        }

        $python = $this->option('python') ?: config('services.media_reconcile.python');
        $helper = base_path('scripts/wp_phash.py');
        if (! $this->checkPython($python, $helper)) {
            return self::FAILURE;
        }

        $this->loadCatalog();
        if (empty($this->catalog)) {
            $this->error('Aucun media_file avec un phash valide : rien à réconcilier.');

            return self::FAILURE;
        }

        $this->baseUrl = rtrim($source->url, '/');
        $this->authUser = $source->auth_username;
        $this->authPass = $source->auth_password; // déchiffré via le cast encrypted
        $this->userAgent = (string) config('services.media_reconcile.user_agent');

        $this->info(sprintf(
            'Réconciliation « %s » (wp_source #%d · %s) · seuil=%d · %s · catalogue=%d photos',
            $source->name, $source->id, $this->baseUrl, $threshold,
            $commit ? 'COMMIT' : 'DRY-RUN', count($this->catalog),
        ));
        $this->newLine();

        $matches = [];      // lignes à écrire : [attachment_id, media_file_id, distance, confidence, wp_post_id, source_url]
        $ambiguous = [];    // [attachment_id, candidats] — non écrits
        $noMatch = 0;
        $scanned = 0;
        $page = 1;

        do {
            $attachments = $this->fetchMediaPage($page);
            if ($attachments === null) {
                $this->error("Échec de récupération de la page média #{$page}. Arrêt.");
                break;
            }
            if (empty($attachments)) {
                break;
            }

            // 1) Télécharge les tailles "medium" de la page dans un dossier temp.
            $tmpDir = $this->makeTempDir();
            $tmpByAtt = []; // attachment_id => chemin temp
            foreach ($attachments as $att) {
                if ($limit !== null && $scanned >= $limit) {
                    break;
                }
                $scanned++;
                $downloadUrl = $this->pickMediumUrl($att);
                if (! $downloadUrl) {
                    $noMatch++;

                    continue;
                }
                $path = $tmpDir.'/att-'.$att['id'].'.img';
                if ($this->download($downloadUrl, $path)) {
                    $tmpByAtt[$att['id']] = $path;
                } else {
                    $noMatch++;
                }
            }

            // 2) Calcule tous les phash de la page en un seul appel Python.
            $phashes = $this->computePhashes($python, $helper, $tmpDir, array_values($tmpByAtt));

            // 3) Rapproche chaque attachment du catalogue.
            $byId = collect($attachments)->keyBy('id');
            foreach ($tmpByAtt as $attId => $path) {
                $hex = $phashes[$path] ?? null;
                if (! $hex) {
                    $noMatch++;

                    continue;
                }

                $within = [];
                foreach ($this->catalog as $entry) {
                    $d = PhashMatcher::hamming($hex, $entry['phash']);
                    if ($d !== null && $d <= $threshold) {
                        $within[] = ['id' => $entry['id'], 'distance' => $d];
                    }
                }

                if (empty($within)) {
                    $noMatch++;

                    continue;
                }
                if (count($within) > 1) {
                    // Ambigu : plusieurs candidats sous le seuil → on n'écrit pas.
                    usort($within, fn ($a, $b) => $a['distance'] <=> $b['distance']);
                    $ambiguous[] = ['attachment_id' => $attId, 'candidates' => array_slice($within, 0, 4)];

                    continue;
                }

                $best = $within[0];
                $att = $byId->get($attId, []);
                $matches[] = [
                    'attachment_id' => $attId,
                    'media_file_id' => $best['id'],
                    'distance' => $best['distance'],
                    'confidence' => PhashMatcher::confidence($best['distance']),
                    'wp_post_id' => $this->resolveArticle($att),
                    'source_url' => $att['source_url'] ?? null,
                ];
            }

            $this->cleanTempDir($tmpDir);

            $totalPages = $this->lastTotalPages;
            $page++;
        } while (($limit === null || $scanned < $limit) && $page <= $totalPages);

        return $this->report($source, $matches, $ambiguous, $noMatch, $scanned, $commit);
    }

    // ── Résolution de la source ─────────────────────────────────────────────

    /**
     * Résout la ligne wp_sources depuis l'argument {site} : id numérique, --wp-source-id,
     * ou match approximatif sur le nom/url. Plusieurs matches (Vantour EN/DE, média
     * partagé WPML) → source canonique = plus petit id, override via --wp-source-id.
     */
    private function resolveSource(): ?WpSource
    {
        $forcedId = $this->option('wp-source-id');
        $site = (string) $this->argument('site');

        if ($forcedId !== null) {
            $source = WpSource::find((int) $forcedId);
            if (! $source) {
                $this->error("Aucune wp_sources avec l'id {$forcedId} (--wp-source-id).");
            }

            return $source;
        }

        if (ctype_digit($site)) {
            $source = WpSource::find((int) $site);
            if (! $source) {
                $this->error("Aucune wp_sources avec l'id {$site}.");
            }

            return $source;
        }

        $matches = WpSource::where('name', 'like', "%{$site}%")
            ->orWhere('url', 'like', "%{$site}%")
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->error("Aucune wp_sources ne correspond à « {$site} ». Sources disponibles :");
            foreach (WpSource::orderBy('id')->get(['id', 'name', 'url']) as $s) {
                $this->line("  #{$s->id} · {$s->name} · {$s->url}");
            }
            $this->line('→ relance avec un id numérique ou --wp-source-id=<id>.');

            return null;
        }

        if ($matches->count() > 1) {
            $this->warn("Plusieurs sources matchent « {$site} » (média partagé WPML ?) :");
            foreach ($matches as $s) {
                $this->line("  #{$s->id} · {$s->name} · {$s->url}");
            }
            $canonical = $matches->first();
            $this->warn("→ source canonique retenue : #{$canonical->id}. Override possible via --wp-source-id=<id>.");

            return $canonical;
        }

        return $matches->first();
    }

    // ── Python / phash ──────────────────────────────────────────────────────

    private function checkPython(string $python, string $helper): bool
    {
        if (! is_file($helper)) {
            $this->error("Helper Python introuvable : {$helper}");

            return false;
        }
        if (! is_file($python) && ! $this->which($python)) {
            $this->error("Binaire Python introuvable : {$python} (configure services.media_reconcile.python / MEDIA_PHASH_PYTHON, ou --python=).");

            return false;
        }

        $result = Process::run([$python, $helper, '--selfcheck']);
        if (! $result->successful() || ! str_contains($result->output(), 'ok')) {
            $this->error('Le venv Python ne fournit pas imagehash+PIL. Utilise le venv du pipeline d\'ingest Mac.');
            $this->line(trim($result->errorOutput()));

            return false;
        }

        return true;
    }

    private function which(string $bin): bool
    {
        return Process::run(['/usr/bin/env', 'which', $bin])->successful();
    }

    /**
     * Calcule le phash de chaque fichier via un seul appel au helper Python.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null> chemin => phash 16-hex (ou null si illisible)
     */
    private function computePhashes(string $python, string $helper, string $tmpDir, array $paths): array
    {
        if (empty($paths)) {
            return [];
        }
        $manifest = $tmpDir.'/manifest.json';
        file_put_contents($manifest, json_encode(array_values($paths)));

        $result = Process::timeout(600)->run([$python, $helper, $manifest]);
        if (! $result->successful()) {
            $this->warn('Calcul phash échoué sur cette page : '.trim($result->errorOutput()));

            return [];
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ── Catalogue ───────────────────────────────────────────────────────────

    private function loadCatalog(): void
    {
        MediaFile::whereNotNull('phash')
            ->select(['id', 'phash'])
            ->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    // On ne garde que les phash 16-hex (mêmes valides que hamming()).
                    if (PhashMatcher::hamming($row->phash, $row->phash) !== null) {
                        $this->catalog[] = ['id' => $row->id, 'phash' => $row->phash];
                    }
                }
            });
    }

    // ── Appels WordPress ────────────────────────────────────────────────────

    private int $lastTotalPages = 1;

    /**
     * Récupère une page de la média-library (images uniquement).
     *
     * @return list<array<string, mixed>>|null null en cas d'échec HTTP
     */
    private function fetchMediaPage(int $page): ?array
    {
        $request = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout(30)
            ->acceptJson();
        if ($this->authUser && $this->authPass) {
            $request = $request->withBasicAuth($this->authUser, $this->authPass);
        }

        $response = $request->get("{$this->baseUrl}/wp-json/wp/v2/media", [
            'per_page' => 100,
            'page' => $page,
            'media_type' => 'image',
            '_fields' => 'id,source_url,media_details,post,mime_type,title',
        ]);

        if (! $response->successful()) {
            // WP renvoie 400 "rest_post_invalid_page_number" au-delà de la dernière page.
            if ($response->status() === 400) {
                return [];
            }

            return null;
        }

        $this->lastTotalPages = (int) $response->header('X-WP-TotalPages', (string) $page);

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * Choisit l'URL de la taille "medium" (pas le full) avec repli medium_large → source_url.
     *
     * @param  array<string, mixed>  $att
     */
    private function pickMediumUrl(array $att): ?string
    {
        $sizes = $att['media_details']['sizes'] ?? [];
        foreach (['medium', 'medium_large', 'large'] as $size) {
            if (! empty($sizes[$size]['source_url'])) {
                return $sizes[$size]['source_url'];
            }
        }

        return $att['source_url'] ?? null;
    }

    private function download(string $url, string $path): bool
    {
        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent])->timeout(30)->get($url);
            if (! $response->successful()) {
                return false;
            }
            file_put_contents($path, $response->body());

            return filesize($path) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Résolution de l'article ─────────────────────────────────────────────

    /**
     * Résout l'article qui utilise l'attachment : post_parent, sinon featured_media,
     * sinon référence de l'URL/ID dans le content d'un post. null si introuvable.
     *
     * @param  array<string, mixed>  $att
     */
    private function resolveArticle(array $att): ?int
    {
        $parent = (int) ($att['post'] ?? 0);
        if ($parent > 0) {
            return $parent;
        }

        $attId = (int) ($att['id'] ?? 0);
        $sourceUrl = $att['source_url'] ?? null;

        foreach ($this->loadPosts() as $post) {
            if ($post['featured_media'] === $attId) {
                return $post['id'];
            }
        }
        foreach ($this->loadPosts() as $post) {
            $content = $post['content'];
            if ($sourceUrl && str_contains($content, $sourceUrl)) {
                return $post['id'];
            }
            if ($attId && (str_contains($content, "wp-image-{$attId}") || str_contains($content, "attachment_id={$attId}"))) {
                return $post['id'];
            }
        }

        return null;
    }

    /**
     * Charge (une seule fois) les posts publiés pour la résolution d'article.
     *
     * @return list<array{id:int, featured_media:int, content:string, link:string}>
     */
    private function loadPosts(): array
    {
        if ($this->posts !== null) {
            return $this->posts;
        }

        $this->posts = [];
        $page = 1;
        do {
            $request = Http::withHeaders(['User-Agent' => $this->userAgent])->timeout(30)->acceptJson();
            if ($this->authUser && $this->authPass) {
                $request = $request->withBasicAuth($this->authUser, $this->authPass);
            }
            $response = $request->get("{$this->baseUrl}/wp-json/wp/v2/posts", [
                'per_page' => 100,
                'page' => $page,
                'status' => 'publish',
                '_fields' => 'id,featured_media,link,content',
            ]);
            if (! $response->successful()) {
                break;
            }
            foreach ($response->json() as $post) {
                $this->posts[] = [
                    'id' => (int) ($post['id'] ?? 0),
                    'featured_media' => (int) ($post['featured_media'] ?? 0),
                    'content' => (string) ($post['content']['rendered'] ?? ''),
                    'link' => (string) ($post['link'] ?? ''),
                ];
            }
            $totalPages = (int) $response->header('X-WP-TotalPages', (string) $page);
            $page++;
        } while ($page <= $totalPages);

        return $this->posts;
    }

    // ── Écriture / rapport ──────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $matches
     * @param  list<array<string, mixed>>  $ambiguous
     */
    private function report(WpSource $source, array $matches, array $ambiguous, int $noMatch, int $scanned, bool $commit): int
    {
        if (! empty($matches)) {
            $this->info(count($matches).' match(s) :');
            $this->table(
                ['attachment', 'media_file', 'distance', 'confiance', 'article'],
                array_map(fn ($m) => [
                    $m['attachment_id'],
                    $m['media_file_id'],
                    $m['distance'],
                    $m['confidence'],
                    $m['wp_post_id'] ?? '—',
                ], $matches),
            );
        }

        if (! empty($ambiguous)) {
            $this->warn(count($ambiguous).' ambigu(s) (≥ 2 candidats sous le seuil — NON écrits) :');
            foreach ($ambiguous as $a) {
                $cands = implode(', ', array_map(fn ($c) => "#{$c['id']}(d={$c['distance']})", $a['candidates']));
                $this->line("  attachment {$a['attachment_id']} → {$cands}");
            }
        }

        $created = 0;
        $updated = 0;
        if ($commit && ! empty($matches)) {
            foreach ($matches as $m) {
                [$isNew] = $this->writeLink($source->id, $m);
                $isNew ? $created++ : $updated++;
            }
            $this->info("Écriture : {$created} créés · {$updated} déjà présents (mis à jour).");
        } elseif (! empty($matches)) {
            $this->warn('DRY-RUN : aucune écriture. Relance avec --commit pour écrire les '.count($matches).' match(s).');
        }

        $this->newLine();
        $this->info(sprintf(
            'Résumé : %d scannés · %d matchés · %d ambigus · %d sans match',
            $scanned, count($matches), count($ambiguous), $noMatch,
        ));

        return self::SUCCESS;
    }

    /**
     * Écrit le lien phash via la même sémantique que mark-wp-used (firstOrNew idempotent).
     *
     * @param  array<string, mixed>  $m
     * @return array{0: bool} [créé ?]
     */
    private function writeLink(int $wpSourceId, array $m): array
    {
        $publication = MediaPublication::firstOrNew([
            'media_file_id' => $m['media_file_id'],
            'wp_source_id' => $wpSourceId,
            'wp_attachment_id' => $m['attachment_id'],
        ]);
        $isNew = ! $publication->exists;

        $publication->wp_post_id = $m['wp_post_id'];
        $publication->match_method = 'phash';
        $publication->match_confidence = $m['confidence'];
        if (! empty($m['source_url'])) {
            $publication->external_url = $m['source_url'];
        }
        if ($isNew) {
            $publication->published_at = now();
        }
        $publication->save();

        if ($isNew) {
            MediaFile::where('id', $m['media_file_id'])->increment('publication_count');
        }

        return [$isNew];
    }

    // ── Fichiers temporaires ────────────────────────────────────────────────

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/rsmax-reconcile-'.getmypid().'-'.uniqid();
        @mkdir($dir, 0700, true);

        return $dir;
    }

    private function cleanTempDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
