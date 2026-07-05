<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Process;

/**
 * Calcule des phash perceptuels via le helper Python du pipeline d'ingest Mac
 * (scripts/wp_phash.py exécuté avec le venv configuré). Centralise l'appel Process
 * pour que reconcile-wp et backfill-phash utilisent EXACTEMENT le même algo.
 */
class PhashComputer
{
    public function __construct(
        private readonly string $python,
        private readonly string $helper,
    ) {}

    /**
     * Construit depuis la config, avec override optionnel du binaire Python.
     */
    public static function fromConfig(?string $pythonOverride = null): self
    {
        return new self(
            $pythonOverride ?: (string) config('services.media_reconcile.python'),
            base_path('scripts/wp_phash.py'),
        );
    }

    /**
     * Vérifie que le helper et le venv (imagehash + PIL) sont utilisables.
     * Retourne un message d'erreur, ou null si tout est OK.
     */
    public function unavailableReason(): ?string
    {
        if (! is_file($this->helper)) {
            return "Helper Python introuvable : {$this->helper}";
        }
        if (! is_file($this->python) && ! $this->which($this->python)) {
            return "Binaire Python introuvable : {$this->python} (config services.media_reconcile.python / MEDIA_PHASH_PYTHON, ou --python=).";
        }

        $result = Process::run([$this->python, $this->helper, '--selfcheck']);
        if (! $result->successful() || ! str_contains($result->output(), 'ok')) {
            return 'Le venv Python ne fournit pas imagehash+PIL. Utilise le venv du pipeline d\'ingest Mac. '.trim($result->errorOutput());
        }

        return null;
    }

    /**
     * Calcule le phash de chaque fichier en un seul appel Python.
     *
     * @param  list<string>  $paths  Chemins absolus des images
     * @return array<string, string|null> chemin => phash 16-hex (null si illisible)
     */
    public function compute(string $manifestPath, array $paths): array
    {
        if (empty($paths)) {
            return [];
        }
        file_put_contents($manifestPath, json_encode(array_values($paths)));

        $result = Process::timeout(600)->run([$this->python, $this->helper, $manifestPath]);
        if (! $result->successful()) {
            return [];
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function which(string $bin): bool
    {
        return Process::run(['/usr/bin/env', 'which', $bin])->successful();
    }
}
