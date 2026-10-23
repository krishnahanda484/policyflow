<?php

namespace App\Insurer;

final class InsurerIssueResult
{
    public const ISSUED = 'ISSUED';

    public const PENDING = 'PENDING';   // accepted, final answer comes by callback

    public const REJECTED = 'REJECTED'; // permanent refusal

    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $policyNumber = null,
        public readonly ?string $reason = null,
    ) {}

    /** Safe-to-store representation of the response (no customer data). */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'policy_number' => $this->policyNumber,
            'reason' => $this->reason,
        ], fn ($v) => $v !== null);
    }
}
