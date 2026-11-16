<?php

namespace App\Console\Commands;

use App\Models\InboxItem;
use Illuminate\Console\Command;

/**
 * Supprime les messages reçus de plus de `--days` jours, tous comptes (décision de Xavier, 16/11/2026) : 49 672 lignes
 * sur 60 154 avaient plus d'un mois. L'import ignore les mêmes (`InboxSyncService`), sans quoi un réseau qui relit
 * d'anciens fils les recréerait « non lus ». Une réponse en cours n'est jamais supprimée.
 */
class PurgeOldInboxItemsCommand extends Command
{
    public const DAYS = 30;

    protected $signature = 'inbox:purge-old {--days=30 : Âge maximal en jours} {--commit : Supprimer pour de vrai}';

    protected $description = 'Supprime les messages reçus de plus d’un mois, sans toucher aux réponses en cours';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(7, (int) $this->option('days')));
        $old = InboxItem::where(fn ($q) => $q->where('posted_at', '<', $cutoff)
            ->orWhere(fn ($q) => $q->whereNull('posted_at')->where('created_at', '<', $cutoff)))
            ->where(fn ($q) => $q->whereNull('reply_scheduled_at')->orWhereNotNull('replied_at'));
        $count = (clone $old)->count();
        if (! $this->option('commit')) {
            $this->line("{$count} message(s) de plus de {$this->option('days')} jours (à blanc).");

            return self::SUCCESS;
        }
        $deleted = 0;
        do {
            $batch = (clone $old)->limit(2000)->pluck('id');
            $deleted += InboxItem::whereIn('id', $batch)->delete();
        } while ($batch->count() === 2000);
        $this->info("{$deleted} message(s) de plus de {$this->option('days')} jours supprimé(s).");

        return self::SUCCESS;
    }
}
