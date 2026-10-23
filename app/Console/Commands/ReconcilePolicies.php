<?php

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Console\Command;

class ReconcilePolicies extends Command
{
    protected $signature = 'policies:reconcile';

    protected $description = 'Resolve uncertain requests (NEEDS_RECONCILIATION, stale PENDING_CONFIRMATION / SUBMITTED) by asking the insurer';

    public function handle(IssuanceService $issuance): int
    {
        $pendingCutoff = now()->subSeconds(config('insurer.stale_pending_seconds'));
        $submittedCutoff = now()->subSeconds(config('insurer.stale_submitted_seconds'));

        $ids = PolicyRequest::query()
            ->where(fn ($q) => $q
                ->where('status', PolicyStatus::NEEDS_RECONCILIATION->value)
                ->orWhere(fn ($q) => $q->where('status', PolicyStatus::PENDING_CONFIRMATION->value)->where('updated_at', '<=', $pendingCutoff))
                ->orWhere(fn ($q) => $q->where('status', PolicyStatus::SUBMITTED->value)->where('updated_at', '<=', $submittedCutoff)))
            ->pluck('id');

        foreach ($ids as $id) {
            // submit() is the safe "resume" entry point: it reconciles instead of re-sending.
            $status = $issuance->submit($id)->status->value;
            $this->line("policy_request {$id}: {$status}");
        }

        $this->info("Processed {$ids->count()} request(s).");

        return self::SUCCESS;
    }
}
