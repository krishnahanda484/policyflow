<?php

namespace Tests\Concerns;

use App\Insurer\InsurerGateway;
use Tests\Support\CountingInsurer;

trait InteractsWithPolicyRequests
{
    protected CountingInsurer $insurer;

    /** Swap the gateway for the counting mock so tests can assert how often the insurer was called. */
    protected function useCountingInsurer(): void
    {
        $this->insurer = new CountingInsurer;
        $this->app->instance(InsurerGateway::class, $this->insurer);
    }

    /** The example from the assignment (premium 225.00). All data is fabricated. */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    /** Create a request through the API and return its id. */
    protected function createPolicyRequest(array $overrides = []): int
    {
        return $this->postJson('/api/policy-requests', $this->payload($overrides))
            ->assertSuccessful()
            ->json('data.id');
    }

    protected function callbackPayload(string $type, string $eventId, string $providerReference, ?string $policyNumber = null, ?string $externalReference = null): array
    {
        return array_filter([
            'event_id' => $eventId,
            'provider_reference' => $providerReference,
            'external_reference' => $externalReference,
            'type' => $type,
            'policy_number' => $policyNumber,
        ], fn ($v) => $v !== null);
    }
}
