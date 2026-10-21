<?php

namespace App\Console\Commands;

use App\Models\Thread;
use App\Models\ThreadSegmentPlatform;
use App\Services\TelegramNotificationService;
use App\Services\ThreadPublishingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublishScheduledThreadsCommand extends Command
{
    protected $signature = 'threads:publish-scheduled';

    protected $description = 'Publie les fils de discussion programmes dont la date est passee';

    public function handle(ThreadPublishingService $service): int
    {
        // Threads impose 35s entre segments : un fil long peut etre tres lent.
        set_time_limit(0);

        $threads = Thread::readyToPublish()
            ->with(['segments', 'socialAccounts.platform'])
            ->orderBy('scheduled_at')
            ->get();

        if ($threads->isEmpty()) {
            $this->info('Aucun fil a publier.');

            return Command::SUCCESS;
        }

        $this->info("Publication de {$threads->count()} fil(s)...");

        foreach ($threads as $thread) {
            $preview = Str::limit($thread->segments->first()?->content_fr ?? '', 50);

            if ($thread->socialAccounts->isEmpty()) {
                $this->warn("  -> Fil #{$thread->id} ignore : aucun compte social lie.");
                Log::warning('Fil programme sans compte social', ['thread_id' => $thread->id]);

                continue;
            }

            $this->info("  -> Fil #{$thread->id}: {$preview}");

            try {
                $service->publishAll($thread);
                $thread->refresh();
                $this->line("     statut: {$thread->status}");

                // Un fil qui s'interrompt au milieu ne levait aucune exception : le
                // statut `partial` partait en base et personne n'etait prevenu. Des
                // fils d'aout-septembre 2026 ont ainsi perdu leurs derniers segments
                // sur Threads pendant des semaines, sans un signal.
                if ($thread->status !== 'published') {
                    $this->notifyIncomplete($thread);
                }
            } catch (\Throwable $e) {
                // Un fil en echec ne doit pas bloquer les suivants ; on le sort de la
                // file pour eviter qu'il soit retente indefiniment a chaque minute.
                $thread->update(['status' => 'failed']);
                $this->error("     echec: {$e->getMessage()}");
                Log::error('Echec publication fil programme', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);

                TelegramNotificationService::notifyThreadError(
                    $thread->id,
                    'exception',
                    $e->getMessage(),
                );
            }
        }

        $this->info('Termine.');

        return Command::SUCCESS;
    }

    /**
     * Previent qu'un fil n'est pas sorti en entier, en nommant le reseau fautif,
     * le segment ou ca s'est arrete et l'erreur brute de la plateforme — c'est ce
     * qui permet de distinguer un alea a retenter d'un contenu a corriger.
     */
    private function notifyIncomplete(Thread $thread): void
    {
        $segmentIds = $thread->segments->pluck('id');
        $total = $segmentIds->count();

        foreach ($thread->socialAccounts as $account) {
            if (($account->pivot->status ?? null) === 'published') {
                continue;
            }

            $rows = ThreadSegmentPlatform::query()
                ->whereIn('thread_segment_id', $segmentIds)
                ->where('social_account_id', $account->id)
                ->with('threadSegment')
                ->get();

            $published = $rows->where('status', 'published')->count();

            // La premiere erreur fait foi : les suivantes ne sont que la cascade
            // « Previous segment failed », qui ne dit rien de la cause.
            $firstFailure = $rows
                ->where('status', 'failed')
                ->sortBy(fn (ThreadSegmentPlatform $r) => $r->threadSegment->position ?? 0)
                ->first();

            if (! $firstFailure) {
                continue;
            }

            $position = $firstFailure->threadSegment->position ?? '?';

            TelegramNotificationService::notifyThreadError(
                $thread->id,
                $account->platform->slug ?? 'unknown',
                $firstFailure->error_message ?? 'erreur inconnue',
                $account->name,
                "segment {$position} — {$published}/{$total} publies",
            );

            $this->warn("     ! {$account->name}: {$published}/{$total} segments (arret au {$position})");
        }
    }
}
