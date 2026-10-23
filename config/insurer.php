<?php

return [
    // Total issuance attempts allowed per request (first attempt + retries).
    'max_attempts' => (int) env('INSURER_MAX_ATTEMPTS', 3),

    // A request stuck in SUBMITTED longer than this is assumed to have lost its worker (crash/timeout)
    // and is reconciled instead of re-sent.
    'stale_submitted_seconds' => (int) env('INSURER_STALE_SUBMITTED_SECONDS', 300),

    // PENDING_CONFIRMATION older than this is picked up by `php artisan policies:reconcile`.
    'stale_pending_seconds' => (int) env('INSURER_STALE_PENDING_SECONDS', 900),

    // After a timeout, immediately ask the insurer for the status instead of waiting for the command.
    'reconcile_inline' => (bool) env('INSURER_RECONCILE_INLINE', true),

    // Development only: let the intake API choose the mock scenario with `mock_scenario`.
    'allow_scenario_override' => (bool) env('INSURER_ALLOW_SCENARIO_OVERRIDE', env('APP_ENV') !== 'production'),
    'default_scenario' => env('INSURER_DEFAULT_SCENARIO', 'SUCCESS'),

    'mock' => [
        // Enables POST /api/mock-insurer/deliver-callbacks (simulates the insurer sending its callbacks).
        'controls_enabled' => (bool) env('MOCK_INSURER_CONTROLS', env('APP_ENV') !== 'production'),
        // Simulates an outage of the provider status lookup (used by tests and interview demos).
        'lookup_fails' => (bool) env('MOCK_INSURER_LOOKUP_FAILS', false),
    ],
];
