<?php

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use PHPUnit\Framework\TestCase;

class PolicyStatusTest extends TestCase
{
    public function test_issued_is_terminal(): void
    {
        $this->assertSame([], PolicyStatus::ISSUED->allowedTransitions());

        foreach (PolicyStatus::cases() as $to) {
            $this->assertFalse(PolicyStatus::ISSUED->canTransitionTo($to), "ISSUED must not move to {$to->value}");
        }
    }

    public function test_no_state_can_regress_to_received_or_validated(): void
    {
        foreach (PolicyStatus::cases() as $from) {
            $this->assertFalse($from->canTransitionTo(PolicyStatus::RECEIVED));
            $this->assertFalse($from->canTransitionTo(PolicyStatus::VALIDATED) && $from !== PolicyStatus::RECEIVED);
        }
    }

    public function test_happy_path_is_allowed(): void
    {
        $this->assertTrue(PolicyStatus::RECEIVED->canTransitionTo(PolicyStatus::VALIDATED));
        $this->assertTrue(PolicyStatus::VALIDATED->canTransitionTo(PolicyStatus::SUBMITTED));
        $this->assertTrue(PolicyStatus::SUBMITTED->canTransitionTo(PolicyStatus::ISSUED));
        $this->assertTrue(PolicyStatus::SUBMITTED->canTransitionTo(PolicyStatus::PENDING_CONFIRMATION));
        $this->assertTrue(PolicyStatus::PENDING_CONFIRMATION->canTransitionTo(PolicyStatus::ISSUED));
    }

    public function test_failed_can_only_be_retried(): void
    {
        $this->assertSame([PolicyStatus::SUBMITTED], PolicyStatus::FAILED->allowedTransitions());
    }
}
