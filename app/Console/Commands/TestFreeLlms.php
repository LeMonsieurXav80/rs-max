<?php

namespace App\Console\Commands;

use App\Models\FreeLlmModel;
use App\Services\Llm\FreeLlmTestService;
use Illuminate\Console\Command;

class TestFreeLlms extends Command
{
    protected $signature = 'free-llms:test {--id= : Tester uniquement un modele par son id}';

    protected $description = 'Healthcheck des modeles LLM gratuits (ping texte/vision, classe les erreurs)';

    public function handle(FreeLlmTestService $service): int
    {
        $id = $this->option('id');

        if ($id) {
            $model = FreeLlmModel::find($id);
            if (! $model) {
                $this->error("Modele introuvable : {$id}");

                return self::FAILURE;
            }
            $this->line("Test {$model->qualified_name}...");
            $r = $service->testModel($model);
            $this->line("  status={$r['status']} latency={$r['latency_ms']}ms".($r['error'] ? '  err='.mb_substr($r['error'], 0, 100) : ''));

            return self::SUCCESS;
        }

        $this->info('Healthcheck des modeles LLM gratuits...');
        $summary = $service->testAll();
        $this->info("Termine : {$summary['ok']} OK / {$summary['failed']} KO sur {$summary['tested']} modeles testes.");

        return self::SUCCESS;
    }
}
