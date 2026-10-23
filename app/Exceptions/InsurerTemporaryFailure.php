<?php

namespace App\Exceptions;

/** The insurer explicitly said "try again later". Nothing was issued. */
class InsurerTemporaryFailure extends InsurerException {}
