<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Support\TagNormalizer;
use Illuminate\Console\Command;

/**
 * Applique des décisions de nettoyage de tags depuis un JSON.
 *
 * Format JSON attendu :
 *   {
 *     "<tag_source>": "SUPPR" | "FUSION:<tag>" | "SPLIT:<t1>,<t2>,..." | "GARDE",
 *     ...
 *   }
 *
 * Pour chaque MediaFile qui contient un des tags source :
 *   - SUPPR             -> tag retiré
 *   - FUSION:<tag>      -> tag remplacé par <tag> (puis dédup via TagNormalizer)
 *   - SPLIT:<t1>,<t2>   -> tag remplacé par la liste (puis dédup)
 *   - GARDE             -> aucune modification (sert juste à tracer la décision)
 */
class ApplyTagDecisionsCommand extends Command
{
    protected $signature = 'media:apply-tag-decisions
        {file : Chemin du JSON des décisions}
        {--dry-run : Affiche les changements sans rien écrire}
        {--show=10 : Nombre de photos modifiées à détailler}';

    protected $description = 'Applique des décisions de nettoyage de tags depuis un JSON {tag_source -> action}.';

    public function handle(): int
    {
        $file = $this->argument('file');
        $dry = (bool) $this->option('dry-run');
        $showLimit = (int) $this->option('show');

        if (! is_file($file)) {
            $this->error("Fichier introuvable : $file");

            return self::FAILURE;
        }

        $decisions = json_decode((string) file_get_contents($file), true);
        if (! is_array($decisions)) {
            $this->error('JSON invalide.');

            return self::FAILURE;
        }

        // Stats par catégorie de décision
        $stats = ['SUPPR' => 0, 'FUSION' => 0, 'SPLIT' => 0, 'GARDE' => 0, 'INVALID' => 0];
        foreach ($decisions as $action) {
            if ($action === 'SUPPR') {
                $stats['SUPPR']++;
            } elseif ($action === 'GARDE') {
                $stats['GARDE']++;
            } elseif (str_starts_with((string) $action, 'FUSION:')) {
                $stats['FUSION']++;
            } elseif (str_starts_with((string) $action, 'SPLIT:')) {
                $stats['SPLIT']++;
            } else {
                $stats['INVALID']++;
            }
        }
        $this->info(($dry ? '[DRY-RUN] ' : '').'Décisions chargées : '.count($decisions));
        $this->line("  SUPPR  : {$stats['SUPPR']}");
        $this->line("  FUSION : {$stats['FUSION']}");
        $this->line("  SPLIT  : {$stats['SPLIT']}");
        $this->line("  GARDE  : {$stats['GARDE']}");
        if ($stats['INVALID'] > 0) {
            $this->warn("  INVALID: {$stats['INVALID']} (ignorées)");
        }
        $this->newLine();

        $sourceTags = array_keys($decisions);

        // Index toutes les photos contenant au moins un tag à traiter (en SQL côté JSON ce serait fragile,
        // on filtre côté PHP : 3920 tags x 2825 photos, ça reste trivial).
        $photosChanged = 0;
        $samplesShown = 0;

        MediaFile::whereNotNull('thematic_tags')
            ->select(['id', 'filename', 'thematic_tags'])
            ->chunkById(200, function ($rows) use (
                &$photosChanged, &$samplesShown,
                $decisions, $sourceTags, $dry, $showLimit
            ) {
                foreach ($rows as $row) {
                    $before = $row->thematic_tags ?? [];
                    if (! is_array($before)) {
                        continue;
                    }

                    // Y a-t-il au moins un tag concerné par les décisions ?
                    $hit = array_intersect($before, $sourceTags);
                    if (empty($hit)) {
                        continue;
                    }

                    $intermediate = [];
                    foreach ($before as $tag) {
                        $action = $decisions[$tag] ?? null;
                        if ($action === null || $action === 'GARDE') {
                            $intermediate[] = $tag;

                            continue;
                        }
                        if ($action === 'SUPPR') {
                            continue;
                        }
                        if (str_starts_with($action, 'FUSION:')) {
                            $target = trim(substr($action, 7));
                            if ($target !== '') {
                                $intermediate[] = $target;
                            }

                            continue;
                        }
                        if (str_starts_with($action, 'SPLIT:')) {
                            $parts = array_map('trim', explode(',', substr($action, 6)));
                            foreach ($parts as $p) {
                                if ($p !== '') {
                                    $intermediate[] = $p;
                                }
                            }

                            continue;
                        }
                        // INVALID -> on garde l'original par sécurité.
                        $intermediate[] = $tag;
                    }

                    // Re-normalise et dédup
                    $after = TagNormalizer::normalize($intermediate) ?? [];

                    if ($before === $after) {
                        continue;
                    }

                    $photosChanged++;

                    if ($samplesShown < $showLimit) {
                        $this->line('  #'.$row->id.' '.$row->filename);
                        $this->line('    avant: '.json_encode($before, JSON_UNESCAPED_UNICODE));
                        $this->line('    apres: '.json_encode($after, JSON_UNESCAPED_UNICODE));
                        $samplesShown++;
                    }

                    if (! $dry) {
                        MediaFile::where('id', $row->id)->update(['thematic_tags' => $after]);
                    }
                }
            });

        $this->newLine();
        $this->info('Photos modifiées : '.$photosChanged);
        if ($dry) {
            $this->warn('--dry-run : aucune modification écrite.');
        }

        return self::SUCCESS;
    }
}
