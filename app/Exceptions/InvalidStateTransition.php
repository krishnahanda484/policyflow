<?php

namespace App\Exceptions;

use App\Enums\PolicyStatus;
use RuntimeException;

/** A transition the state machine does not allow (HTTP 409). */
class InvalidStateTransition extends RuntimeException
{
    public function __construct(public readonly PolicyStatus $from, public readonly PolicyStatus $to)
    {
        parent::__construct("Transition {$from->value} -> {$to->value} is not allowed.");
    }
}
