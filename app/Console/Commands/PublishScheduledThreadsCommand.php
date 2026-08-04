<?php

namespace App\Console\Commands;

use App\Models\Thread;
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
            } catch (\Throwable $e) {
                // Un fil en echec ne doit pas bloquer les suivants ; on le sort de la
                // file pour eviter qu'il soit retente indefiniment a chaque minute.
                $thread->update(['status' => 'failed']);
                $this->error("     echec: {$e->getMessage()}");
                Log::error('Echec publication fil programme', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Termine.');

        return Command::SUCCESS;
    }
}
