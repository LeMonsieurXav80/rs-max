<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Support\TagNormalizer;
use Illuminate\Console\Command;

class NormalizeMediaTagsCommand extends Command
{
    protected $signature = 'media:normalize-tags
        {--dry-run : Affiche les changements sans écrire en base}
        {--show=20 : Nombre de photos modifiées à détailler dans la sortie}';

    protected $description = 'Réécrit thematic_tags via TagNormalizer (minuscules, sans accents, séparateur=espace, dédup). Idempotent.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $showLimit = (int) $this->option('show');

        $total = MediaFile::whereNotNull('thematic_tags')->count();
        $this->info(($dry ? '[DRY-RUN] ' : '').'Scan de '.$total.' photos avec thematic_tags non null.');

        $changed = 0;
        $unchanged = 0;
        $emptied = 0;
        $deltaBeforeKeys = [];
        $deltaAfterKeys = [];
        $samplesShown = 0;

        MediaFile::whereNotNull('thematic_tags')
            ->select(['id', 'filename', 'thematic_tags'])
            ->chunkById(200, function ($rows) use (
                &$changed, &$unchanged, &$emptied, &$samplesShown,
                &$deltaBeforeKeys, &$deltaAfterKeys, $dry, $showLimit
            ) {
                foreach ($rows as $row) {
                    $before = $row->thematic_tags ?? [];
                    if (! is_array($before)) {
                        continue;
                    }
                    $after = TagNormalizer::normalize($before) ?? [];

                    if ($before === $after) {
                        $unchanged++;

                        continue;
                    }

                    $changed++;
                    if (empty($after) && ! empty($before)) {
                        $emptied++;
                    }

                    foreach ($before as $b) {
                        $deltaBeforeKeys[(string) $b] = true;
                    }
                    foreach ($after as $a) {
                        $deltaAfterKeys[(string) $a] = true;
                    }

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
        $this->info('Photos modifiées      : '.$changed);
        $this->info('Photos inchangées     : '.$unchanged);
        if ($emptied > 0) {
            $this->warn('Photos vidées (toutes les valeurs ont été rejetées) : '.$emptied);
        }
        $this->info('Tags distincts avant  (au moins 1 photo touchée) : '.count($deltaBeforeKeys));
        $this->info('Tags distincts après  (au moins 1 photo touchée) : '.count($deltaAfterKeys));

        if ($dry) {
            $this->newLine();
            $this->warn('--dry-run : aucune modification écrite. Relance sans --dry-run pour appliquer.');
        }

        return self::SUCCESS;
    }
}
