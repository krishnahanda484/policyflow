<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InsurerException;
use App\Exceptions\InsurerTemporaryFailure;
use App\Exceptions\InsurerTimeout;
use App\Exceptions\IssuanceNotAllowed;
use App\Insurer\InsurerGateway;
use App\Insurer\InsurerIssueResult;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drives a request from VALIDATED to a final state.
 *
 * Golden rules (each one is covered by a test):
 *  - every state change happens in a short DB transaction that holds a row lock on the request;
 *  - the insurer is NEVER called while that lock is held (no network I/O inside a transaction);
 *  - a timeout never means "not issued": we ask the insurer (lookup) before sending anything again;
 *  - submit() is safe to call any number of times: it resumes or no-ops, it never double-sends.
 */
class IssuanceService
{
    public function __construct(
        private InsurerGateway $insurer,
        private PolicyStateMachine $machine,
    ) {}

    /** Start or safely resume issuance. Returns the request in whatever state it ended up. */
    public function submit(int $id): PolicyRequest
    {
        // Phase 1 (locked, no network): decide what to do and, if we will call the insurer,
        // durably record that an attempt is in flight (VALIDATED/FAILED -> SUBMITTED).
        [$action, $attemptId] = DB::transaction(function () use ($id) {
            $request = PolicyRequest::lockForUpdate()->findOrFail($id);

            return match ($request->status) {
                PolicyStatus::ISSUED => ['noop', null],
                PolicyStatus::SUBMITTED => $this->isStale($request)
                    ? $this->markForReconciliation($request, 'In-flight attempt went stale; checking with insurer')
                    : ['noop', null], // another worker is calling the insurer right now
                PolicyStatus::PENDING_CONFIRMATION,
                PolicyStatus::NEEDS_RECONCILIATION => ['reconcile', null],
                PolicyStatus::FAILED => $this->beginRetry($request),
                PolicyStatus::VALIDATED => ['issue', $this->beginAttempt($request, 'Submitting to insurer')->id],
                default => throw new IssuanceNotAllowed("A request in status {$request->status->value} cannot be submitted."),
            };
        });

        // Phase 2 (unlocked): talk to the insurer.
        match ($action) {
            'issue' => $this->executeIssue($id, $attemptId),
            'reconcile' => $this->reconcile($id),
            default => null,
        };

        return PolicyRequest::findOrFail($id);
    }

    /**
     * Resolve an uncertain request by asking the insurer, never by re-sending blindly.
     * Called for NEEDS_RECONCILIATION and PENDING_CONFIRMATION.
     */
    public function reconcile(int $id): void
    {
        $request = PolicyRequest::findOrFail($id);

        try {
            $lookup = $this->insurer->lookup($request->providerIdempotencyKey());
        } catch (InsurerException $e) {
            // Cannot find out. Stay uncertain; a later submit/`policies:reconcile` tries again.
            $this->noteError($id, 'Status lookup failed: '.$e->getMessage());

            return;
        }

        $reissueAttemptId = DB::transaction(function () use ($id, $lookup) {
            $request = PolicyRequest::lockForUpdate()->findOrFail($id);

            // Someone else (callback, other worker) already resolved it while we were calling out.
            if (! in_array($request->status, [PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::NEEDS_RECONCILIATION], true)) {
                return null;
            }

            $context = ['lookup' => $lookup->toArray()];

            if ($lookup->found && $lookup->status === 'ISSUED') {
                $this->applyIssued($request, $lookup->policyNumber, $lookup->providerReference, 'status lookup');

                return null;
            }

            if ($lookup->found) { // insurer has it, still pending
                if ($request->status === PolicyStatus::NEEDS_RECONCILIATION) {
                    $this->machine->transition($request, PolicyStatus::PENDING_CONFIRMATION,
                        'Insurer has the request but has not confirmed it yet', $context,
                        ['provider_reference' => $lookup->providerReference]);
                }

                return null;
            }

            // Not found: the insurer has no record, so nothing was issued.
            if ($request->status === PolicyStatus::PENDING_CONFIRMATION) {
                $this->machine->transition($request, PolicyStatus::NEEDS_RECONCILIATION,
                    'Insurer reports no record of a request we believed was accepted', $context);

                return null;
            }

            if ($request->attempt_count >= config('insurer.max_attempts')) {
                $this->machine->transition($request, PolicyStatus::FAILED,
                    'Insurer has no record and the attempt limit is reached', $context,
                    ['last_error' => 'Attempt limit reached', 'failure_retryable' => false]);

                return null;
            }

            // Only now is a re-issue safe.
            return $this->beginAttempt($request, 'Insurer confirmed it has no record; re-issuing', $context)->id;
        });

        if ($reissueAttemptId) {
            $this->executeIssue($id, $reissueAttemptId);
        }
    }

    /**
     * Marks a request ISSUED exactly once. Idempotent and never regresses:
     * an already ISSUED request keeps its original policy number, whatever arrives later.
     * Must be called inside a transaction that holds the row lock.
     *
     * @return bool true if this call performed the transition
     */
    public function applyIssued(PolicyRequest $request, ?string $policyNumber, ?string $providerReference, string $source): bool
    {
        if ($request->status === PolicyStatus::ISSUED) {
            if ($policyNumber !== null && $request->policy_number !== $policyNumber) {
                // Two different policy numbers for one request: a provider bug. Keep the first, shout.
                Log::warning('Conflicting policy number ignored', ['policy_request_id' => $request->id, 'source' => $source]);
            }

            return false;
        }

        if ($policyNumber === null || ! $request->status->canTransitionTo(PolicyStatus::ISSUED)) {
            return false;
        }

        $this->machine->transition($request, PolicyStatus::ISSUED, "Insurer confirmed issuance ({$source})",
            ['provider_reference' => $providerReference, 'source' => $source],
            [
                'policy_number' => $policyNumber,
                'provider_reference' => $providerReference ?? $request->provider_reference,
                'issued_at' => now(),
                'last_error' => null,
                'failure_retryable' => false,
            ]);

        return true;
    }

    // ---------------------------------------------------------------- internals

    private function executeIssue(int $id, int $attemptId): void
    {
        $request = PolicyRequest::findOrFail($id);
        $attempt = IssuanceAttempt::findOrFail($attemptId);

        try {
            $result = $this->insurer->issue(
                $attempt->idempotency_key,
                $request->mock_scenario,
                $attempt->attempt_number,
                [
                    'plan_code' => $request->plan_code,
                    'sum_insured' => $request->sum_insured,
                    'premium' => $request->premium,
                    'currency' => $request->currency,
                ],
            );
        } catch (InsurerTimeout $e) {
            // We do NOT know whether the policy exists. Park the request, then ask the insurer.
            $this->recordAttemptOutcome($id, $attemptId, 'TIMEOUT', null, $e->getMessage(), function ($r) {
                $this->machine->transition($r, PolicyStatus::NEEDS_RECONCILIATION,
                    'Insurer call timed out; outcome unknown', [], ['last_error' => 'Insurer call timed out']);
            });

            if (config('insurer.reconcile_inline')) {
                $this->reconcile($id);
            }

            return;
        } catch (InsurerTemporaryFailure $e) {
            $this->recordAttemptOutcome($id, $attemptId, 'TEMPORARY_FAILURE', null, $e->getMessage(), function ($r) use ($e) {
                $this->machine->transition($r, PolicyStatus::FAILED, 'Insurer reported a temporary failure',
                    [], ['last_error' => $e->getMessage(), 'failure_retryable' => true]);
            });

            return;
        }

        $this->recordAttemptOutcome($id, $attemptId, $result->status, $result, $result->reason, function ($r) use ($result) {
            match ($result->status) {
                InsurerIssueResult::ISSUED => $this->applyIssued($r, $result->policyNumber, $result->providerReference, 'issue response'),
                InsurerIssueResult::PENDING => $this->machine->transition($r, PolicyStatus::PENDING_CONFIRMATION,
                    'Insurer accepted the request; waiting for confirmation',
                    ['provider_reference' => $result->providerReference],
                    ['provider_reference' => $result->providerReference, 'last_response' => $result->toArray()]),
                default => $this->machine->transition($r, PolicyStatus::FAILED, 'Insurer rejected the request',
                    [], ['last_error' => $result->reason ?? 'Rejected by insurer', 'failure_retryable' => false,
                        'last_response' => $result->toArray()]),
            };
        });
    }

    /**
     * Phase 3 helper: under the row lock, store the attempt's outcome and (if the request is still
     * SUBMITTED) apply the matching transition. If a callback already moved the request on while
     * we were waiting for the insurer, we only keep the attempt record and leave the state alone.
     */
    private function recordAttemptOutcome(int $id, int $attemptId, string $outcome, ?InsurerIssueResult $result, ?string $error, callable $apply): void
    {
        DB::transaction(function () use ($id, $attemptId, $outcome, $result, $error, $apply) {
            $request = PolicyRequest::lockForUpdate()->findOrFail($id);

            IssuanceAttempt::whereKey($attemptId)->update([
                'outcome' => $outcome,
                'provider_reference' => $result?->providerReference,
                'response' => $result ? json_encode($result->toArray()) : null,
                'error' => $error,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($request->status === PolicyStatus::SUBMITTED) {
                $apply($request);
            } else {
                Log::info('Attempt finished but request already moved on', [
                    'policy_request_id' => $id, 'status' => $request->status->value, 'outcome' => $outcome,
                ]);
            }
        });
    }

    /** SUBMITTED/FAILED/NEEDS_RECONCILIATION -> SUBMITTED with a fresh attempt row. Caller holds the lock. */
    private function beginAttempt(PolicyRequest $request, string $reason, array $context = []): IssuanceAttempt
    {
        $attemptNumber = $request->attempt_count + 1;

        $this->machine->transition($request, PolicyStatus::SUBMITTED, $reason,
            $context + ['attempt' => $attemptNumber], ['attempt_count' => $attemptNumber]);

        return IssuanceAttempt::create([
            'policy_request_id' => $request->id,
            'attempt_number' => $attemptNumber, // UNIQUE(policy_request_id, attempt_number)
            'idempotency_key' => $request->providerIdempotencyKey(),
            'outcome' => 'IN_FLIGHT',
            'started_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: ?int} */
    private function beginRetry(PolicyRequest $request): array
    {
        if (! $request->failure_retryable) {
            throw new IssuanceNotAllowed('This request failed permanently and cannot be retried.');
        }
        if ($request->attempt_count >= config('insurer.max_attempts')) {
            throw new IssuanceNotAllowed('The maximum number of issuance attempts has been reached.');
        }

        return ['issue', $this->beginAttempt($request, 'Retrying after temporary failure')->id];
    }

    /** @return array{0: string, 1: null} */
    private function markForReconciliation(PolicyRequest $request, string $reason): array
    {
        $this->machine->transition($request, PolicyStatus::NEEDS_RECONCILIATION, $reason);

        return ['reconcile', null];
    }

    private function isStale(PolicyRequest $request): bool
    {
        return $request->updated_at->lt(now()->subSeconds(config('insurer.stale_submitted_seconds')));
    }

    private function noteError(int $id, string $message): void
    {
        PolicyRequest::whereKey($id)->update(['last_error' => $message, 'updated_at' => now()]);
    }
}
