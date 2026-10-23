<?php

namespace App\Http\Requests;

use App\Enums\MockScenario;
use App\Services\PremiumCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authentication is out of scope for this assessment (see README).
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:64'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'destination' => ['required', 'string', Rule::in(array_keys(PremiumCalculator::DESTINATIONS))],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(array_keys(PremiumCalculator::PLANS))],
            'sum_insured' => ['required', 'integer', Rule::in(array_keys(PremiumCalculator::COVERAGES))],
            // Development-only knob that selects the mock insurer behaviour. Ignored in production.
            'mock_scenario' => ['sometimes', 'nullable', 'string', Rule::enum(MockScenario::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'trip_end must be on or after trip_start.',
            'plan_code.in' => 'plan_code must be one of: '.implode(', ', array_keys(PremiumCalculator::PLANS)).'.',
            'sum_insured.in' => 'sum_insured must be one of: '.implode(', ', array_keys(PremiumCalculator::COVERAGES)).'.',
            'destination.in' => 'destination must be one of: '.implode(', ', array_keys(PremiumCalculator::DESTINATIONS)).'.',
        ];
    }

    /** Extra cross-field rule: the traveller cannot be born after the trip starts. */
    public function after(): array
    {
        return [function ($validator) {
            $dob = $this->input('date_of_birth');
            $start = $this->input('trip_start');
            if (is_string($dob) && is_string($start) && $dob > $start && ! $validator->errors()->has('date_of_birth')) {
                $validator->errors()->add('date_of_birth', 'date_of_birth must not be after trip_start.');
            }
        }];
    }
}
