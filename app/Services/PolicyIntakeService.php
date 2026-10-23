<?php

namespace App\Services;

use App\Enums\MockScenario;
use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PolicyIntakeService
{
    public function __construct(
        private PremiumCalculator $calculator,
        private PolicyStateMachine $machine,
    ) {}

    /**
     * Creates the request, or returns the one that already exists for (agent_id, client_request_id).
     *
     * Duplicate safety, in layers:
     *  1. cheap lookup first (the common retry case, no write at all);
     *  2. otherwise INSERT; the UNIQUE(agent_id, client_request_id) index decides the winner when
     *     two requests race past step 1;
     *  3. the loser catches the unique violation and returns the winner's row.
     *
     * @param  array<string, mixed>  $data  validated input
     * @return array{0: PolicyRequest, 1: bool} [request, created]
     */
    public function handle(array $data): array
    {
        if ($existing = $this->find($data['agent_id'], $data['client_request_id'])) {
            return [$existing, false];
        }

        $quote = $this->calculator->calculate(
            $data['plan_code'],
            $data['date_of_birth'],
            $data['trip_start'],
            $data['trip_end'],
            $data['destination'],
            (int) $data['sum_insured'],
        );

        $scenario = MockScenario::SUCCESS->value;
        if (config('insurer.allow_scenario_override') && ! empty($data['mock_scenario'])) {
            $scenario = $data['mock_scenario'];
        } elseif (config('insurer.allow_scenario_override')) {
            $scenario = config('insurer.default_scenario');
        }

        try {
            // The transaction makes request + audit rows atomic. The unique violation is caught
            // OUTSIDE it, because on PostgreSQL a failed statement poisons the whole transaction.
            $request = DB::transaction(function () use ($data, $quote, $scenario) {
                $request = PolicyRequest::create([
                    'client_request_id' => $data['client_request_id'],
                    'agent_id' => $data['agent_id'],
                    'customer_name' => $data['customer_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'destination' => $data['destination'],
                    'trip_start' => $data['trip_start'],
                    'trip_end' => $data['trip_end'],
                    'plan_code' => $data['plan_code'],
                    'sum_insured' => (int) $data['sum_insured'],
                    'status' => PolicyStatus::RECEIVED,
                    'mock_scenario' => $scenario,
                    ...$quote->toSnapshot(),
                ]);

                $this->machine->recordInitial($request, 'Request received');
                // Input was validated and priced above, so the request is VALIDATED straight away.
                $this->machine->transition($request, PolicyStatus::VALIDATED, 'Validation and premium calculation passed', [
                    'premium' => $quote->premium,
                ]);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race: someone inserted the same (agent_id, client_request_id) first.
            return [$this->find($data['agent_id'], $data['client_request_id'])
                ?? throw new \RuntimeException('Duplicate detected but original row not found.'), false];
        }

        return [$request->fresh(), true];
    }

    protected function find(string $agentId, string $clientRequestId): ?PolicyRequest
    {
        return PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }
}
