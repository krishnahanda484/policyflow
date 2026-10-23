<?php

namespace App\Enums;

/**
 * Lifecycle of a policy request. The allowed transitions below are the single source of truth;
 * PolicyStateMachine refuses everything else (e.g. ISSUED -> SUBMITTED).
 */
enum PolicyStatus: string
{
    case RECEIVED = 'RECEIVED';
    case VALIDATED = 'VALIDATED';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case ISSUED = 'ISSUED';
    case FAILED = 'FAILED';
    case NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RECEIVED => [self::VALIDATED, self::FAILED],
            self::VALIDATED => [self::SUBMITTED, self::FAILED],
            // SUBMITTED = an attempt is in flight. Outcome decides the next state.
            self::SUBMITTED => [self::ISSUED, self::PENDING_CONFIRMATION, self::NEEDS_RECONCILIATION, self::FAILED],
            // Waiting for the insurer's callback (or a status lookup).
            self::PENDING_CONFIRMATION => [self::ISSUED, self::NEEDS_RECONCILIATION, self::FAILED],
            // We do not know whether the insurer issued. SUBMITTED is only reachable here, after the
            // insurer confirmed it has no record, so a re-issue is safe.
            self::NEEDS_RECONCILIATION => [self::ISSUED, self::PENDING_CONFIRMATION, self::SUBMITTED, self::FAILED],
            // Retry of a retriable failure.
            self::FAILED => [self::SUBMITTED],
            // Terminal. Nothing may overwrite a confirmed policy.
            self::ISSUED => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
