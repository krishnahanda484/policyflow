<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\ConcurrentModification;
use App\Exceptions\InvalidStateTransition;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;

/**
 * The only place that changes PolicyRequest.status.
 * Callers must run inside a DB transaction holding a row lock (lockForUpdate); the
 * compare-and-swap UPDATE below is a second line of defence if they forget.
 */
class PolicyStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write in the same save
     * @param  array<string, mixed>  $context  stored on the audit row (no personal data)
     */
    public function transition(
        PolicyRequest $request,
        PolicyStatus $to,
        string $reason,
        array $context = [],
        array $attributes = [],
    ): void {
        $from = $request->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStateTransition($from, $to);
        }

        // Compare-and-swap: only succeeds if nobody changed the status since we read it.
        $swapped = PolicyRequest::whereKey($request->getKey())
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => now()]);

        if ($swapped !== 1) {
            throw new ConcurrentModification("PolicyRequest {$request->getKey()} changed concurrently.");
        }

        $request->forceFill(array_merge($attributes, ['status' => $to]))->save();

        PolicyStateHistory::create([
            'policy_request_id' => $request->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'context' => $context ?: null,
        ]);
    }

    /** Records the very first state (no "from"). */
    public function recordInitial(PolicyRequest $request, string $reason): void
    {
        PolicyStateHistory::create([
            'policy_request_id' => $request->getKey(),
            'from_status' => null,
            'to_status' => $request->status,
            'reason' => $reason,
        ]);
    }
}
