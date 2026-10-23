<?php

namespace App\Insurer;

use App\Exceptions\InsurerTemporaryFailure;
use App\Exceptions\InsurerTimeout;

/**
 * The only way PolicyFlow talks to an insurer. Swap the binding in AppServiceProvider
 * to point at a real provider; nothing else in the application changes.
 */
interface InsurerGateway
{
    /**
     * Ask the insurer to issue a policy. The idempotency key is identical on every attempt
     * for the same request, so a well-behaved insurer never issues twice.
     *
     * @param  array<string, mixed>  $payload  Non-sensitive quote data (plan, premium, sum insured).
     *
     * @throws InsurerTimeout no answer: the policy may or may not exist
     * @throws InsurerTemporaryFailure explicit "try again later": nothing was issued
     */
    public function issue(string $idempotencyKey, string $scenario, int $attemptNumber, array $payload): InsurerIssueResult;

    /**
     * Ask the insurer what it knows about a request, by our idempotency key.
     *
     * @throws InsurerTimeout when the lookup itself cannot be answered
     */
    public function lookup(string $idempotencyKey): InsurerLookupResult;
}
