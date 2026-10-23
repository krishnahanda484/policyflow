<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Exceptions\ConcurrentModification;
use App\Exceptions\InvalidStateTransition;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Services\PolicyStateMachine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithPolicyRequests;
use Tests\TestCase;

class IssuanceTest extends TestCase
{
    use InteractsWithPolicyRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCountingInsurer();
    }

    private function statuses(int $id): array
    {
        return array_column($this->getJson("/api/policy-requests/{$id}")->json('data.history'), 'to_status');
    }

    // ------------------------------------------------------------------ happy path

    public function test_successful_issuance_produces_exactly_one_policy_number(): void
    {
        $id = $this->createPolicyRequest();

        $response = $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1)
            ->assertJsonPath('data.premium', '225.00');

        $policyNumber = $response->json('data.policy_number');
        $this->assertNotEmpty($policyNumber);
        $this->assertNotEmpty($response->json('data.provider_reference'));
        $this->assertSame(['RECEIVED', 'VALIDATED', 'SUBMITTED', 'ISSUED'], $this->statuses($id));
        $this->assertSame(1, MockInsurerPolicy::count());
        $this->assertSame(1, $this->insurer->issueCalls);
        $this->assertSame($policyNumber, PolicyRequest::find($id)->policy_number);
    }

    public function test_repeated_submit_does_not_call_the_insurer_again_or_change_the_policy(): void
    {
        $id = $this->createPolicyRequest();
        $first = $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();

        foreach (range(1, 4) as $_) {
            $again = $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();
            $this->assertSame($first->json('data.policy_number'), $again->json('data.policy_number'));
        }

        $this->assertSame(1, $this->insurer->issueCalls);
        $this->assertSame(1, MockInsurerPolicy::count());
        $this->assertSame(1, $this->getJson("/api/policy-requests/{$id}")->json('data.attempt_count'));
        $this->assertDatabaseCount('issuance_attempts', 1);
    }

    public function test_every_issued_policy_gets_a_different_policy_number(): void
    {
        $a = $this->createPolicyRequest(['client_request_id' => 'A']);
        $b = $this->createPolicyRequest(['client_request_id' => 'B']);

        $numA = $this->postJson("/api/policy-requests/{$a}/submit")->json('data.policy_number');
        $numB = $this->postJson("/api/policy-requests/{$b}/submit")->json('data.policy_number');

        $this->assertNotSame($numA, $numB);
    }

    public function test_database_enforces_unique_policy_numbers(): void
    {
        $a = $this->createPolicyRequest(['client_request_id' => 'A']);
        $b = $this->createPolicyRequest(['client_request_id' => 'B']);
        $this->postJson("/api/policy-requests/{$a}/submit")->assertOk();
        $number = PolicyRequest::find($a)->policy_number;

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('policy_requests')->where('id', $b)->update(['policy_number' => $number]);
    }

    public function test_submitting_an_unknown_request_returns_404(): void
    {
        $this->postJson('/api/policy-requests/12345/submit')->assertNotFound();
    }

    // ------------------------------------------------------------------ reliability 2: timeout after success

    public function test_timeout_after_issue_is_reconciled_without_issuing_twice(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);

        $response = $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED');

        $this->assertNotEmpty($response->json('data.policy_number'));
        $this->assertSame(1, $this->insurer->issueCalls, 'A timeout must never trigger a second issue call.');
        $this->assertSame(1, $this->insurer->lookupCalls, 'The status lookup resolved it.');
        $this->assertSame(1, MockInsurerPolicy::count());
        $this->assertSame(1, $response->json('data.attempt_count'));
        $this->assertSame(
            ['RECEIVED', 'VALIDATED', 'SUBMITTED', 'NEEDS_RECONCILIATION', 'ISSUED'],
            $this->statuses($id),
        );
        $this->assertSame('TIMEOUT', $response->json('data.attempts.0.outcome'));
    }

    public function test_timeout_stays_unresolved_while_lookup_is_down_and_never_reissues(): void
    {
        config(['insurer.mock.lookup_fails' => true]);
        $id = $this->createPolicyRequest(['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        // Retrying while the insurer cannot tell us anything: still no second issue call.
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);
        $this->assertSame(1, $this->insurer->issueCalls);

        // Lookup recovers: the existing policy is adopted, nothing is re-sent.
        config(['insurer.mock.lookup_fails' => false]);
        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED');

        $this->assertSame(1, $this->insurer->issueCalls);
        $this->assertSame(1, MockInsurerPolicy::count());
    }

    public function test_reconcile_command_resolves_requests_left_uncertain(): void
    {
        config(['insurer.mock.lookup_fails' => true]);
        $id = $this->createPolicyRequest(['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);

        config(['insurer.mock.lookup_fails' => false]);
        $this->artisan('policies:reconcile')->assertSuccessful();

        $this->assertSame('ISSUED', PolicyRequest::find($id)->status->value);
        $this->assertSame(1, $this->insurer->issueCalls);
    }

    public function test_reissue_happens_only_after_the_insurer_confirms_it_has_no_record(): void
    {
        $id = $this->createPolicyRequest();
        // Timeout where the insurer really did NOT create anything: we only know that because lookup says so.
        DB::table('policy_requests')->where('id', $id)->update(['status' => 'NEEDS_RECONCILIATION', 'attempt_count' => 1]);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2);

        $this->assertSame(1, $this->insurer->lookupCalls);
        $this->assertSame(1, $this->insurer->issueCalls);
        $this->assertSame(1, MockInsurerPolicy::count());
    }

    // ------------------------------------------------------------------ concurrency guards on submit

    public function test_submit_while_another_attempt_is_in_flight_does_not_call_the_insurer(): void
    {
        $id = $this->createPolicyRequest();
        DB::table('policy_requests')->where('id', $id)->update(['status' => 'SUBMITTED', 'attempt_count' => 1, 'updated_at' => now()]);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'SUBMITTED');

        $this->assertSame(0, $this->insurer->issueCalls);
        $this->assertSame(0, $this->insurer->lookupCalls);
    }

    public function test_stale_submitted_request_is_reconciled_instead_of_resent(): void
    {
        $id = $this->createPolicyRequest();
        // Worker died after the insurer issued: provider has the policy, we are stuck in SUBMITTED.
        $this->insurer->issue("PF-{$id}", 'SUCCESS', 1, []);
        DB::table('policy_requests')->where('id', $id)->update([
            'status' => 'SUBMITTED', 'attempt_count' => 1, 'updated_at' => now()->subHour(),
        ]);
        $this->insurer->issueCalls = 0;

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED');

        $this->assertSame(0, $this->insurer->issueCalls);
        $this->assertSame(1, MockInsurerPolicy::count());
    }

    // ------------------------------------------------------------------ temporary failure + retry

    public function test_temporary_failure_is_recorded_and_retry_succeeds_once_keeping_the_premium(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'TEMPORARY_FAILURE']);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure_retryable', true)
            ->assertJsonPath('data.attempt_count', 1)
            ->assertJsonPath('data.premium', '225.00');
        $this->assertSame(0, MockInsurerPolicy::count());

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2)
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.attempts.0.outcome', 'TEMPORARY_FAILURE')
            ->assertJsonPath('data.attempts.1.outcome', 'ISSUED');

        $this->assertSame(1, MockInsurerPolicy::count());
        $this->assertSame(
            ['RECEIVED', 'VALIDATED', 'SUBMITTED', 'FAILED', 'SUBMITTED', 'ISSUED'],
            $this->statuses($id),
        );
    }

    public function test_retries_stop_at_the_attempt_limit(): void
    {
        config(['insurer.max_attempts' => 1]);
        $id = $this->createPolicyRequest(['mock_scenario' => 'TEMPORARY_FAILURE']);

        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(502);
        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error', 'issuance_not_allowed');
        $this->assertSame(1, $this->insurer->issueCalls);
    }

    // ------------------------------------------------------------------ delayed + duplicate callbacks

    public function test_delayed_callback_stays_pending_until_the_event_arrives(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'DELAYED_CALLBACK']);

        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION')
            ->assertJsonPath('data.policy_number', null);

        // Re-submitting while pending asks the insurer; it has not confirmed yet, so nothing changes.
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);
        $this->assertSame(1, $this->insurer->issueCalls);

        $this->postJson('/api/mock-insurer/deliver-callbacks')->assertOk()->assertJsonPath('data.deliveries', 1);

        $this->getJson("/api/policy-requests/{$id}")
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1);
        $this->assertNotNull(PolicyRequest::find($id)->policy_number);
    }

    public function test_duplicate_callbacks_are_applied_exactly_once(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'DUPLICATE_CALLBACK']);
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);

        $this->postJson('/api/mock-insurer/deliver-callbacks')->assertOk()->assertJsonPath('data.deliveries', 3);

        $show = $this->getJson("/api/policy-requests/{$id}")->assertJsonPath('data.status', 'ISSUED');
        $this->assertSame(1, array_count_values($this->statuses($id))['ISSUED']);
        $this->assertDatabaseCount('provider_events', 1);
        $this->assertDatabaseHas('provider_events', ['duplicate_count' => 2, 'result' => 'APPLIED']);
        $this->assertSame(1, MockInsurerPolicy::count());
        $this->assertNotEmpty($show->json('data.policy_number'));
    }

    public function test_callback_endpoint_is_idempotent_over_http(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $ref = $this->postJson("/api/policy-requests/{$id}/submit")->json('data.provider_reference');
        $event = $this->callbackPayload('POLICY_ISSUED', 'evt-1', $ref, 'POL-ABC');

        $this->postJson('/api/mock-insurer/callback', $event)
            ->assertOk()->assertJsonPath('data.result', 'APPLIED')->assertJsonPath('data.status', 'ISSUED');
        $this->postJson('/api/mock-insurer/callback', $event)
            ->assertOk()->assertJsonPath('data.result', 'DUPLICATE')->assertJsonPath('data.policy_number', 'POL-ABC');
        $this->postJson('/api/mock-insurer/callback', $event)
            ->assertOk()->assertJsonPath('data.result', 'DUPLICATE');

        $this->assertDatabaseCount('provider_events', 1);
        $this->assertSame(1, array_count_values($this->statuses($id))['ISSUED']);
    }

    public function test_issued_state_is_never_overwritten_by_later_callbacks(): void
    {
        $id = $this->createPolicyRequest();
        $issued = $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();
        $ref = $issued->json('data.provider_reference');
        $number = $issued->json('data.policy_number');

        // A late failure event with a NEW event id must not undo the policy.
        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('POLICY_FAILED', 'evt-fail', $ref))
            ->assertOk()->assertJsonPath('data.result', 'IGNORED')->assertJsonPath('data.status', 'ISSUED');

        // A second "issued" event carrying a different policy number must not replace the original.
        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('POLICY_ISSUED', 'evt-other', $ref, 'POL-DIFFERENT'))
            ->assertOk()->assertJsonPath('data.result', 'IGNORED')->assertJsonPath('data.policy_number', $number);

        $this->getJson("/api/policy-requests/{$id}")
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', $number);
        $this->assertSame(['RECEIVED', 'VALIDATED', 'SUBMITTED', 'ISSUED'], $this->statuses($id));
        $this->assertSame(1, $this->insurer->issueCalls);
    }

    public function test_failure_callback_moves_a_pending_request_to_failed_permanently(): void
    {
        $id = $this->createPolicyRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $ref = $this->postJson("/api/policy-requests/{$id}/submit")->json('data.provider_reference');

        $this->postJson('/api/mock-insurer/callback', array_merge(
            $this->callbackPayload('POLICY_FAILED', 'evt-f', $ref), ['reason' => 'Underwriting declined']
        ))->assertOk()->assertJsonPath('data.status', 'FAILED');

        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(409); // not retryable
    }

    public function test_callback_can_arrive_before_the_issue_response_is_processed_via_external_reference(): void
    {
        // Timeout scenario: we never learned the provider reference, but the callback carries our key.
        config(['insurer.mock.lookup_fails' => true]);
        $id = $this->createPolicyRequest(['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(202);
        $this->assertNull(PolicyRequest::find($id)->provider_reference);

        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('POLICY_ISSUED', 'evt-x', 'MOCK-REF-LATE', 'POL-LATE', "PF-{$id}"))
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $this->assertSame('MOCK-REF-LATE', PolicyRequest::find($id)->provider_reference);
    }

    public function test_callback_for_unknown_request_is_404_and_leaves_no_trace(): void
    {
        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('POLICY_ISSUED', 'evt-1', 'NOPE', 'POL-1'))
            ->assertNotFound();
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_invalid_callback_payload_is_422(): void
    {
        $this->postJson('/api/mock-insurer/callback', ['type' => 'POLICY_ISSUED'])->assertStatus(422);
        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('POLICY_ISSUED', 'e', 'r'))
            ->assertStatus(422)->assertJsonValidationErrors(['policy_number']);
        $this->postJson('/api/mock-insurer/callback', $this->callbackPayload('NONSENSE', 'e', 'r'))->assertStatus(422);
    }

    // ------------------------------------------------------------------ state machine guards

    public function test_issued_request_cannot_be_moved_back_by_the_state_machine(): void
    {
        $id = $this->createPolicyRequest();
        $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();

        $this->expectException(InvalidStateTransition::class);
        app(PolicyStateMachine::class)->transition(
            PolicyRequest::findOrFail($id), PolicyStatus::SUBMITTED, 'illegal',
        );
    }

    public function test_stale_in_memory_model_cannot_overwrite_a_newer_state(): void
    {
        $id = $this->createPolicyRequest();
        $stale = PolicyRequest::findOrFail($id);                                   // still VALIDATED in memory
        $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();          // DB now ISSUED

        $this->expectException(ConcurrentModification::class);
        app(PolicyStateMachine::class)->transition($stale, PolicyStatus::SUBMITTED, 'stale writer');
    }

    public function test_provider_reference_is_unique_across_requests(): void
    {
        $a = $this->createPolicyRequest(['client_request_id' => 'A']);
        $b = $this->createPolicyRequest(['client_request_id' => 'B']);
        $this->postJson("/api/policy-requests/{$a}/submit")->assertOk();
        $ref = PolicyRequest::find($a)->provider_reference;

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('policy_requests')->where('id', $b)->update(['provider_reference' => $ref]);
    }

    public function test_mock_lookup_endpoint_reports_what_the_insurer_knows(): void
    {
        $id = $this->createPolicyRequest();
        $this->getJson("/api/mock-insurer/policies/PF-{$id}")->assertOk()->assertJsonPath('data.found', false);

        $this->postJson("/api/policy-requests/{$id}/submit")->assertOk();
        $this->getJson("/api/mock-insurer/policies/PF-{$id}")
            ->assertOk()->assertJsonPath('data.found', true)->assertJsonPath('data.status', 'ISSUED');
    }
}
