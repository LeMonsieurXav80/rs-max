<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\Post;
use App\Models\Thread;
use App\Services\PartnerTagService;
use Illuminate\Console\Command;

/**
 * Réaligne les tags partenaires « auto » des publications sur les photos qu'elles
 * utilisent réellement.
 *
 * Pourquoi cette commande existe : le report photo → publication se déclenche à
 * l'enregistrement du POST, jamais à celui de la PHOTO. Tant que le rattrapage
 * n'était pas branché sur les chemins d'édition des marques (août 2026), retirer
 * une marque d'une photo laissait la ligne pivot en place sur les publications —
 * définitivement pour un contenu déjà publié, qui ne repasse jamais par le
 * formulaire. D'où des comptes rendus partenaires qui remontent des retombées
 * inexistantes.
 *
 * Les tags posés à la main ('manual') ne sont jamais touchés.
 *
 * Dry-run par défaut, comme les autres commandes de rattrapage : --commit écrit.
 */
class ResyncPartnerContentCommand extends Command
{
    protected $signature = 'partners:resync-content
        {--partner= : Limite aux contenus portant ce partenaire (id ou slug), au lieu de tout balayer}
        {--limit= : Nombre maximum de contenus examinés, posts et fils confondus}
        {--commit : Écrit réellement (par défaut: dry-run, aucune écriture)}';

    protected $description = 'Réaligne les tags partenaires « auto » des posts et fils sur leurs photos (dry-run par défaut).';

    public function handle(PartnerTagService $tags): int
    {
        $commit = (bool) $this->option('commit');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $partner = null;
        if ($needle = $this->option('partner')) {
            $partner = ctype_digit((string) $needle)
                ? Partner::find((int) $needle)
                : Partner::where('slug', Partner::slugFor((string) $needle))->first();

            if (! $partner) {
                $this->error("Partenaire introuvable : {$needle}");

                return self::FAILURE;
            }
            $this->line("Périmètre : contenus tagués « {$partner->name} » (id {$partner->id}).");
        } else {
            $this->line('Périmètre : tous les posts avec photos et tous les fils.');
        }

        if (! $commit) {
            $this->warn('DRY-RUN — aucune écriture. Ajouter --commit pour appliquer.');
        }

        $examined = 0;
        $drifted = 0;
        $rows = [];

        foreach ([Post::class, Thread::class] as $model) {
            $query = $model === Post::class
                ? Post::query()->whereNotNull('media')
                : Thread::query()->whereHas('segments', fn ($q) => $q->whereNotNull('media'));

            // Restreint au partenaire visé : on ne relit pas toute la base pour
            // corriger un seul sur-taguage.
            if ($partner) {
                $query->whereHas('partners', fn ($q) => $q->where('partners.id', $partner->id));
            }

            foreach ($query->lazyById(200) as $content) {
                if ($limit !== null && $examined >= $limit) {
                    break 2;
                }
                $examined++;

                $drift = $tags->autoTagDrift($content);
                if ($drift === null) {
                    continue;
                }
                $drifted++;

                $label = ($model === Post::class ? 'post' : 'fil').' #'.$content->id;
                $rows[] = [
                    $label,
                    $content->status ?? '—',
                    $this->names($drift['removed']),
                    $this->names($drift['added']),
                ];

                if ($commit) {
                    $model === Post::class ? $tags->syncPost($content) : $tags->syncThread($content);
                }
            }
        }

        if ($rows) {
            $this->table(['Contenu', 'Statut', 'Tags retirés', 'Tags ajoutés'], $rows);
        }

        $this->newLine();
        $this->info("Examinés : {$examined} — désalignés : {$drifted}.");

        if ($drifted > 0 && ! $commit) {
            $this->warn('Relancer avec --commit pour appliquer.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int,int>  $ids
     */
    private function names(array $ids): string
    {
        if (! $ids) {
            return '—';
        }

        return Partner::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');
    }
}
