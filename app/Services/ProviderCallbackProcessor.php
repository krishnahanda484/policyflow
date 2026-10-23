<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Applies insurer callbacks exactly once, however many times they are delivered.
 *
 * Idempotency key = the provider's event_id, protected by UNIQUE(provider_events.event_id).
 * The event row and the resulting state change are written in ONE transaction, so there is no
 * window where an event is "recorded but not applied" (which would make a redelivery a lost update).
 */
class ProviderCallbackProcessor
{
    public const APPLIED = 'APPLIED';

    public const DUPLICATE = 'DUPLICATE';

    public const IGNORED = 'IGNORED';

    public function __construct(
        private IssuanceService $issuance,
        private PolicyStateMachine $machine,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  validated callback body
     * @return array{result: string, request: PolicyRequest}
     *
     * @throws ModelNotFoundException when the callback refers to a request we do not know
     */
    public function process(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            // INSERT ... ON CONFLICT DO NOTHING / INSERT IGNORE: returns 0 rows if event_id exists.
            // With two simultaneous deliveries the second blocks on the unique index until the
            // first commits, then becomes a no-op: the event is applied once.
            $inserted = ProviderEvent::query()->insertOrIgnore([
                'event_id' => $payload['event_id'],
                'provider_reference' => $payload['provider_reference'],
                'type' => $payload['type'],
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = $this->locateRequest($payload);

            if ($inserted === 0) {
                ProviderEvent::where('event_id', $payload['event_id'])->increment('duplicate_count');

                return ['result' => self::DUPLICATE, 'request' => $request];
            }

            $event = ProviderEvent::where('event_id', $payload['event_id'])->firstOrFail();

            $applied = match ($payload['type']) {
                'POLICY_ISSUED' => $this->issuance->applyIssued(
                    $request, $payload['policy_number'] ?? null, $payload['provider_reference'], 'callback',
                ),
                'POLICY_FAILED' => $this->applyFailed($request, $payload['reason'] ?? 'Insurer reported failure'),
                default => false,
            };

            $event->update([
                'policy_request_id' => $request->id,
                'result' => $applied ? 'APPLIED' : 'IGNORED_INVALID_TRANSITION',
                'processed_at' => now(),
            ]);

            return ['result' => $applied ? self::APPLIED : self::IGNORED, 'request' => $request->refresh()];
        });
    }

    private function applyFailed(PolicyRequest $request, string $reason): bool
    {
        // A failure callback can never undo an ISSUED policy: canTransitionTo(FAILED) is false there.
        if (! $request->status->canTransitionTo(PolicyStatus::FAILED)) {
            return false;
        }

        $this->machine->transition($request, PolicyStatus::FAILED, 'Insurer reported failure via callback', [],
            ['last_error' => $reason, 'failure_retryable' => false]);

        return true;
    }

    /** Finds and row-locks the request by provider reference, falling back to our idempotency key (PF-<id>). */
    private function locateRequest(array $payload): PolicyRequest
    {
        $request = PolicyRequest::where('provider_reference', $payload['provider_reference'])->lockForUpdate()->first();

        if (! $request && ! empty($payload['external_reference']) && preg_match('/^PF-(\d+)$/', $payload['external_reference'], $m)) {
            $request = PolicyRequest::whereKey((int) $m[1])->lockForUpdate()->first();
        }

        return $request ?? throw (new ModelNotFoundException)->setModel(PolicyRequest::class);
    }
}
