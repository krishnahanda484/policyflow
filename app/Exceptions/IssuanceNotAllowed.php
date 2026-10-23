<?php

namespace App\Exceptions;

use RuntimeException;

/** The request is in a state where issuance cannot be started or resumed (HTTP 409). */
class IssuanceNotAllowed extends RuntimeException {}
