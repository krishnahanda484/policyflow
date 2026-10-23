<?php

namespace App\Insurer;

use App\Enums\MockScenario;
use App\Exceptions\InsurerTemporaryFailure;
use App\Exceptions\InsurerTimeout;
use App\Models\MockInsurerEvent;
use App\Models\MockInsurerPolicy;
use App\Services\ProviderCallbackProcessor;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Deterministic fake insurer. Behaviour depends only on the scenario stored on the request
 * and on the attempt number, never on randomness or wall-clock time.
 *
 * It keeps its own "database" (mock_insurer_* tables), de-duplicates on the idempotency key
 * like a good insurer should, and delivers callbacks only when deliverCallbacks() is called.
 *
 * Simplifications: calls are in-process (no HTTP), callbacks are delivered by calling
 * ProviderCallbackProcessor directly, which is the same code the HTTP callback endpoint uses.
 */
class MockInsurer implements InsurerGateway
{
    public function issue(string $idempotencyKey, string $scenario, int $attemptNumber, array $payload): InsurerIssueResult
    {
        // Provider-side idempotency: same key => same policy, never a second one.
        if ($existing = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first()) {
            return $this->resultFor($existing);
        }

        $scenario = MockScenario::tryFrom($scenario) ?? MockScenario::SUCCESS;

        if ($scenario === MockScenario::TEMPORARY_FAILURE && $attemptNumber < 2) {
            throw new InsurerTemporaryFailure('Insurer is temporarily unavailable (simulated).');
        }

        $pending = in_array($scenario, [MockScenario::DELAYED_CALLBACK, MockScenario::DUPLICATE_CALLBACK], true);
        $policy = $this->createPolicy($idempotencyKey, $pending ? 'PENDING' : 'ISSUED', $scenario);

        if ($pending) {
            MockInsurerEvent::firstOrCreate(
                ['event_id' => 'evt-'.$policy->provider_reference.'-issued'],
                [
                    'provider_reference' => $policy->provider_reference,
                    'type' => 'POLICY_ISSUED',
                    'payload' => [
                        'event_id' => 'evt-'.$policy->provider_reference.'-issued',
                        'provider_reference' => $policy->provider_reference,
                        'external_reference' => $idempotencyKey,
                        'type' => 'POLICY_ISSUED',
                        'policy_number' => $policy->policy_number,
                    ],
                    'times_to_deliver' => $scenario === MockScenario::DUPLICATE_CALLBACK ? 3 : 1,
                ],
            );
        }

        if ($scenario === MockScenario::TIMEOUT_AFTER_ISSUE) {
            // The policy now exists at the insurer, but the caller never hears about it.
            throw new InsurerTimeout('Insurer did not answer in time (simulated). The policy was issued.');
        }

        return $this->resultFor($policy);
    }

    public function lookup(string $idempotencyKey): InsurerLookupResult
    {
        if (config('insurer.mock.lookup_fails')) {
            throw new InsurerTimeout('Insurer status lookup is unavailable (simulated).');
        }

        $policy = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();
        if (! $policy) {
            return InsurerLookupResult::notFound();
        }

        return new InsurerLookupResult(
            found: true,
            status: $policy->status,
            providerReference: $policy->provider_reference,
            // A pending policy has no number the outside world may rely on yet.
            policyNumber: $policy->status === 'ISSUED' ? $policy->policy_number : null,
        );
    }

    /**
     * Sends every queued callback (some of them several times) to PolicyFlow.
     * Marks the policy ISSUED at the insurer first: the insurer's truth precedes its callback.
     *
     * @return int number of deliveries made
     */
    public function deliverCallbacks(ProviderCallbackProcessor $receiver): int
    {
        $delivered = 0;

        foreach (MockInsurerEvent::whereColumn('times_delivered', '<', 'times_to_deliver')->orderBy('id')->get() as $event) {
            MockInsurerPolicy::where('provider_reference', $event->provider_reference)->update(['status' => 'ISSUED']);

            while ($event->times_delivered < $event->times_to_deliver) {
                $receiver->process($event->payload);
                $event->increment('times_delivered');
                $delivered++;
            }
        }

        return $delivered;
    }

    private function createPolicy(string $key, string $status, MockScenario $scenario): MockInsurerPolicy
    {
        $digest = strtoupper(substr(hash('sha256', $key), 0, 10)); // deterministic per request

        try {
            return MockInsurerPolicy::create([
                'idempotency_key' => $key,
                'provider_reference' => 'MOCK-REF-'.$digest,
                'policy_number' => 'POL-'.$digest,
                'status' => $status,
                'scenario' => $scenario->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            return MockInsurerPolicy::where('idempotency_key', $key)->firstOrFail();
        }
    }

    private function resultFor(MockInsurerPolicy $policy): InsurerIssueResult
    {
        return $policy->status === 'ISSUED'
            ? new InsurerIssueResult(InsurerIssueResult::ISSUED, $policy->provider_reference, $policy->policy_number)
            : new InsurerIssueResult(InsurerIssueResult::PENDING, $policy->provider_reference);
    }
}
