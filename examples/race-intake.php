<?php

use App\Services\PolicyIntakeService;
use Illuminate\Contracts\Console\Kernel;

// Used by examples/parallel-race.sh: one process = one near-simultaneous intake call.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    [$request, $created] = app(PolicyIntakeService::class)->handle([
        'client_request_id' => 'RACE-1', 'agent_id' => 'AGENT-17', 'customer_name' => 'Race Test',
        'date_of_birth' => '1998-04-10', 'destination' => 'INTERNATIONAL', 'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14', 'plan_code' => 'PLUS', 'sum_insured' => 300000,
    ]);
    echo ($created ? 'CREATED' : 'REUSED')." id={$request->id}\n";
} catch (Throwable $e) {
    echo 'ERROR '.get_class($e).': '.substr($e->getMessage(), 0, 80)."\n";
}
