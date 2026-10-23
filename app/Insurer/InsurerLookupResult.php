<?php

namespace App\Insurer;

final class InsurerLookupResult
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $status = null, // ISSUED | PENDING when found
        public readonly ?string $providerReference = null,
        public readonly ?string $policyNumber = null,
    ) {}

    public static function notFound(): self
    {
        return new self(false);
    }

    public function toArray(): array
    {
        return array_filter([
            'found' => $this->found,
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'policy_number' => $this->policyNumber,
        ], fn ($v) => $v !== null);
    }
}
