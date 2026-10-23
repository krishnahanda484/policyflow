<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A real integration would verify an HMAC signature header here (see README, limitations).
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:100'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['required', Rule::in(['POLICY_ISSUED', 'POLICY_FAILED'])],
            'policy_number' => ['required_if:type,POLICY_ISSUED', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
