<?php

namespace App\Exceptions;

use RuntimeException;

/** Base class for failures while talking to the insurer. */
abstract class InsurerException extends RuntimeException {}
