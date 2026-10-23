<?php

namespace App\Http\Resources;

use App\Models\PolicyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PolicyRequest */
class PolicyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth->toDateString(),
            'destination' => $this->destination,
            'trip_start' => $this->trip_start->toDateString(),
            'trip_end' => $this->trip_end->toDateString(),
            'plan_code' => $this->plan_code,
            'sum_insured' => $this->sum_insured,
            'status' => $this->status->value,
            // Decimal string, never a float: "225.00".
            'premium' => $this->premium,
            'currency' => $this->currency,
            'premium_breakdown' => [
                'travel_days' => $this->travel_days,
                'age_on_trip_start' => $this->age_on_trip_start,
                'base_daily_rate' => $this->base_daily_rate,
                'age_multiplier' => $this->age_multiplier,
                'destination_multiplier' => $this->destination_multiplier,
                'coverage_multiplier' => $this->coverage_multiplier,
            ],
            'policy_number' => $this->policy_number,
            'provider_reference' => $this->provider_reference,
            'attempt_count' => $this->attempt_count,
            'last_response' => $this->last_response,
            'last_error' => $this->last_error,
            'failure_retryable' => $this->failure_retryable,
            'mock_scenario' => $this->mock_scenario,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                'outcome' => $a->outcome,
                'provider_reference' => $a->provider_reference,
                'error' => $a->error,
                'started_at' => $a->started_at?->toIso8601String(),
                'finished_at' => $a->finished_at?->toIso8601String(),
            ])),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'from_status' => $h->from_status?->value,
                'to_status' => $h->to_status->value,
                'reason' => $h->reason,
                'context' => $h->context,
                'at' => $h->created_at->toIso8601String(),
            ])),
        ];
    }
}
