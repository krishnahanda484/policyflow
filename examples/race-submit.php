<?php

use App\Services\IssuanceService;
use Illuminate\Contracts\Console\Kernel;

// Used by examples/parallel-race.sh: one process = one submit call for the request id in argv[1].
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    echo app(IssuanceService::class)->submit((int) $argv[1])->status->value."\n";
} catch (Throwable $e) {
    echo 'ERROR '.get_class($e).': '.substr($e->getMessage(), 0, 80)."\n";
}
