#!/usr/bin/env bash
# Fires N real OS processes at the same time against the configured database.
# WARNING: runs `migrate:fresh` -> wipes the database in .env. Use a scratch database.
# Run from the project root:  bash examples/parallel-race.sh [N]
set -euo pipefail
N="${1:-12}"
php artisan migrate:fresh --force -q

echo "== $N simultaneous intakes with the same agent_id + client_request_id =="
for i in $(seq 1 "$N"); do php examples/race-intake.php & done | sort | uniq -c
wait
php artisan tinker --execute='echo "policy_requests rows: ".App\Models\PolicyRequest::count().PHP_EOL;'

echo "== $N simultaneous submits of the same request (TIMEOUT_AFTER_ISSUE scenario) =="
ID=$(php artisan tinker --execute='[$r]=app(App\Services\PolicyIntakeService::class)->handle(["client_request_id"=>"RACE-2","agent_id"=>"AGENT-17","customer_name"=>"Race Test","date_of_birth"=>"1998-04-10","destination"=>"DOMESTIC","trip_start"=>"2026-11-10","trip_end"=>"2026-11-11","plan_code"=>"BASIC","sum_insured"=>100000,"mock_scenario"=>"TIMEOUT_AFTER_ISSUE"]); echo $r->id;')
for i in $(seq 1 "$N"); do php examples/race-submit.php "$ID" & done | sort | uniq -c
wait
php artisan tinker --execute='echo "insurer-side policies: ".App\Models\MockInsurerPolicy::count().", attempts: ".App\Models\IssuanceAttempt::count().PHP_EOL;'
