<?php

namespace App\Exceptions;

use RuntimeException;

/** Another worker changed the row between our read and our write (HTTP 409, safe to retry). */
class ConcurrentModification extends RuntimeException {}
