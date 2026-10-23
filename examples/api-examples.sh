#!/usr/bin/env bash
# curl walkthrough. Start the app first:  php artisan serve   (http://127.0.0.1:8000)
# Usage: bash examples/api-examples.sh   (needs curl; jq is optional for pretty output)
BASE="${BASE:-http://127.0.0.1:8000/api}"
H=(-H "Content-Type: application/json" -H "Accept: application/json")
RUN="$(date +%s)"   # makes client_request_id unique per run
pp() { if command -v jq >/dev/null; then jq "$@"; else cat; fi; }

body() { # $1 = client_request_id suffix, $2 = scenario
cat <<JSON
{"client_request_id":"AGT-$RUN-$1","agent_id":"AGENT-17","customer_name":"Sample Customer",
 "date_of_birth":"1998-04-10","destination":"INTERNATIONAL","trip_start":"2026-11-10","trip_end":"2026-11-14",
 "plan_code":"PLUS","sum_insured":300000,"mock_scenario":"$2"}
JSON
}

echo "### 1. Create (201) then repeat the same call (200, same id)"
curl -s -i "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 1 SUCCESS)" | sed -n '1p;/^{/p'
curl -s -i "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 1 SUCCESS)" | sed -n '1p;/^Idempotent/p'

echo "### 2. Validation error (422): unsupported plan and coverage"
curl -s "${H[@]}" -X POST "$BASE/policy-requests" -d '{"client_request_id":"x","agent_id":"a","customer_name":"n","date_of_birth":"1990-01-01","destination":"DOMESTIC","trip_start":"2026-11-10","trip_end":"2026-11-09","plan_code":"GOLD","sum_insured":123}' | pp .

echo "### 3. Submit (SUCCESS): ISSUED, then submit again: no second policy"
ID=$(curl -s "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 1 SUCCESS)" | sed 's/.*"id":\([0-9]*\).*/\1/')
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status, policy_number, attempt_count}'
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status, policy_number, attempt_count}'

echo "### 4. TIMEOUT_AFTER_ISSUE: insurer issued but the call timed out -> reconciled, not re-issued"
ID=$(curl -s "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 2 TIMEOUT_AFTER_ISSUE)" | sed 's/.*"id":\([0-9]*\).*/\1/')
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status, policy_number, attempt_count, history: [.history[].to_status]}'

echo "### 5. TEMPORARY_FAILURE: 502 FAILED (retryable), retry -> ISSUED"
ID=$(curl -s "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 3 TEMPORARY_FAILURE)" | sed 's/.*"id":\([0-9]*\).*/\1/')
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status, failure_retryable, last_error}'
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status, policy_number, attempt_count}'

echo "### 6. DUPLICATE_CALLBACK: pending, then the insurer delivers the same event 3 times -> applied once"
ID=$(curl -s "${H[@]}" -X POST "$BASE/policy-requests" -d "$(body 4 DUPLICATE_CALLBACK)" | sed 's/.*"id":\([0-9]*\).*/\1/')
curl -s "${H[@]}" -X POST "$BASE/policy-requests/$ID/submit" | pp '.data | {status}'
curl -s "${H[@]}" -X POST "$BASE/mock-insurer/deliver-callbacks" | pp .
curl -s "${H[@]}" "$BASE/policy-requests/$ID" | pp '.data | {status, policy_number, history: [.history[].to_status]}'

echo "### 7. List with filter + pagination"
curl -s "${H[@]}" "$BASE/policy-requests?status=ISSUED&per_page=5" | pp '{count: (.data|length), meta: .meta}'
