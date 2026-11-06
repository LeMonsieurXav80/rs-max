<?php

namespace App\Jobs;

use App\Models\AgentProposal;
use App\Services\Agent\AgentProposalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SendAgentProposal implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $proposalId) {}

    public function handle(AgentProposalService $proposals): void
    {
        $proposal = AgentProposal::find($this->proposalId);
        if ($proposal) {
            $proposals->send($proposal);
        }
    }
}
