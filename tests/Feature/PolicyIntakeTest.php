<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\PolicyIntakeService;
use App\Services\PolicyStateMachine;
use App\Services\PremiumCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\InteractsWithPolicyRequests;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use InteractsWithPolicyRequests;
    use RefreshDatabase;

    public function test_valid_request_is_created_with_expected_premium_and_status(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->payload());

        $response->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.premium_breakdown.travel_days', 5)
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('meta.created', true);

        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertNotNull($response->json('data.created_at'));
    }

    public function test_intake_writes_audit_history(): void
    {
        $id = $this->createPolicyRequest();

        $history = $this->getJson("/api/policy-requests/{$id}")->json('data.history');

        $this->assertSame(['RECEIVED', 'VALIDATED'], array_column($history, 'to_status'));
        $this->assertNull($history[0]['from_status']);
        $this->assertSame('RECEIVED', $history[1]['from_status']);
    }

    public function test_repeated_client_request_id_returns_the_same_request_with_200(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->payload())->assertCreated();
        $second = $this->postJson('/api/policy-requests', $this->payload())
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('meta.created', false);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseCount('policy_state_history', 2); // no extra audit rows either
    }

    public function test_same_client_request_id_for_a_different_agent_is_a_different_request(): void
    {
        $this->postJson('/api/policy-requests', $this->payload())->assertCreated();
        $this->postJson('/api/policy-requests', $this->payload(['agent_id' => 'AGENT-18']))->assertCreated();

        $this->assertDatabaseCount('policy_requests', 2);
    }

    public function test_replay_with_different_body_returns_the_original_and_never_changes_the_premium(): void
    {
        $this->postJson('/api/policy-requests', $this->payload())->assertCreated();

        $this->postJson('/api/policy-requests', $this->payload(['plan_code' => 'PREMIUM', 'trip_end' => '2026-12-31']))
            ->assertOk()
            ->assertJsonPath('data.plan_code', 'PLUS')
            ->assertJsonPath('data.premium', '225.00');
    }

    public function test_database_unique_constraint_rejects_duplicates_even_without_the_application_check(): void
    {
        $this->createPolicyRequest();

        $this->expectException(UniqueConstraintViolationException::class);
        // Bypass every application-level check on purpose.
        DB::table('policy_requests')->insert($this->rawRow());
    }

    public function test_losing_the_insert_race_returns_the_winners_row(): void
    {
        $winnerId = $this->createPolicyRequest();

        // Simulates: our "does it exist?" lookup ran just before the other request committed.
        // The first lookup sees nothing, the INSERT then hits the unique index, and the service
        // must recover by returning the winner instead of failing or creating a second row.
        $service = new class(new PremiumCalculator, new PolicyStateMachine) extends PolicyIntakeService
        {
            private int $lookups = 0;

            protected function find(string $agentId, string $clientRequestId): ?PolicyRequest
            {
                return $this->lookups++ === 0 ? null : parent::find($agentId, $clientRequestId);
            }
        };

        [$result, $created] = $service->handle($this->payload());

        $this->assertFalse($created);
        $this->assertSame($winnerId, $result->id);
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_many_repeats_create_exactly_one_request(): void
    {
        foreach (range(1, 25) as $_) {
            $this->postJson('/api/policy-requests', $this->payload());
        }

        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_premium_snapshot_is_immutable(): void
    {
        $this->createPolicyRequest();
        $request = PolicyRequest::firstOrFail();

        $this->expectException(LogicException::class);
        $request->update(['premium' => '1.00']);
    }

    public function test_status_can_still_be_updated_normally(): void
    {
        $this->createPolicyRequest();
        $request = PolicyRequest::firstOrFail();
        $request->update(['last_error' => 'x']); // non-snapshot columns stay writable

        $this->assertSame(PolicyStatus::VALIDATED, $request->fresh()->status);
    }

    public function test_missing_required_fields_return_422_with_field_errors(): void
    {
        $this->postJson('/api/policy-requests', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_request_id', 'agent_id', 'customer_name', 'date_of_birth',
                'destination', 'trip_start', 'trip_end', 'plan_code', 'sum_insured',
            ]);

        $this->assertDatabaseCount('policy_requests', 0);
    }

    public function test_trip_end_before_trip_start_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['trip_start' => '2026-11-14', 'trip_end' => '2026-11-10']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_single_day_trip_is_accepted(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['trip_start' => '2026-11-10', 'trip_end' => '2026-11-10']))
            ->assertCreated()
            ->assertJsonPath('data.premium_breakdown.travel_days', 1);
    }

    public function test_malformed_dates_are_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['trip_start' => '10/11/2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['trip_start']);
        $this->postJson('/api/policy-requests', $this->payload(['date_of_birth' => '2030-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_unsupported_plan_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['plan_code' => 'GOLD']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
        $this->postJson('/api/policy-requests', $this->payload(['plan_code' => 'plus'])) // case-sensitive
            ->assertStatus(422);
    }

    public function test_unsupported_sum_insured_is_rejected(): void
    {
        foreach ([200000, 0, -1, 'abc', 100000.5] as $bad) {
            $this->postJson('/api/policy-requests', $this->payload(['sum_insured' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['sum_insured']);
        }
    }

    public function test_unsupported_destination_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['destination' => 'MARS']))
            ->assertStatus(422)->assertJsonValidationErrors(['destination']);
    }

    public function test_traveller_born_after_trip_start_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['date_of_birth' => '2026-11-12']))
            ->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_invalid_requests_are_not_stored_even_if_the_key_repeats(): void
    {
        $this->postJson('/api/policy-requests', $this->payload(['plan_code' => 'GOLD']))->assertStatus(422);
        $this->postJson('/api/policy-requests', $this->payload())->assertCreated();

        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_unknown_request_returns_404_json(): void
    {
        $this->getJson('/api/policy-requests/9999')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_list_supports_status_filter_and_pagination(): void
    {
        foreach (range(1, 5) as $i) {
            $this->createPolicyRequest(['client_request_id' => "AGT-{$i}"]);
        }
        $issued = $this->createPolicyRequest(['client_request_id' => 'AGT-ISSUED']);
        $this->postJson("/api/policy-requests/{$issued}/submit")->assertOk();

        $this->getJson('/api/policy-requests?status=VALIDATED&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/policy-requests?status=ISSUED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $issued);

        $this->getJson('/api/policy-requests?status=BOGUS')->assertStatus(422);
        $this->getJson('/api/policy-requests?per_page=1000')->assertStatus(422);
        $this->getJson('/api/policy-requests')->assertOk()->assertJsonPath('meta.total', 6);
    }

    private function rawRow(): array
    {
        return [
            'client_request_id' => 'AGT-2026-0001', 'agent_id' => 'AGENT-17', 'customer_name' => 'Dup',
            'date_of_birth' => '1990-01-01', 'destination' => 'DOMESTIC', 'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-11', 'plan_code' => 'BASIC', 'sum_insured' => 100000,
            'travel_days' => 2, 'age_on_trip_start' => 36, 'base_daily_rate' => 12, 'age_multiplier' => 1,
            'destination_multiplier' => 1, 'coverage_multiplier' => 1, 'premium' => 24, 'status' => 'VALIDATED',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
