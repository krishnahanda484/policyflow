<?php

namespace Tests\Support;

use App\Insurer\InsurerIssueResult;
use App\Insurer\InsurerLookupResult;
use App\Insurer\MockInsurer;

/** MockInsurer that counts how often PolicyFlow actually calls it. */
class CountingInsurer extends MockInsurer
{
    public int $issueCalls = 0;

    public int $lookupCalls = 0;

    public function issue(string $idempotencyKey, string $scenario, int $attemptNumber, array $payload): InsurerIssueResult
    {
        $this->issueCalls++;

        return parent::issue($idempotencyKey, $scenario, $attemptNumber, $payload);
    }

    public function lookup(string $idempotencyKey): InsurerLookupResult
    {
        $this->lookupCalls++;

        return parent::lookup($idempotencyKey);
    }
}
