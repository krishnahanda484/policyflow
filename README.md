# PolicyFlow: fault-tolerant insurance issuance & reconciliation engine

A small Laravel API that takes travel-insurance purchase requests from agents, prices them, submits them to an
unreliable (mock) insurer and tracks each one to a final outcome **without ever issuing a policy twice**.

* Laravel 12, PHP 8.2+, MySQL 8+ / PostgreSQL (SQLite works too and is used by the tests)
* No paid services, no real insurer, all data fabricated
* Reliability cases implemented and tested: **(1) duplicate intake** and **(2) timeout after successful issue**,
  plus idempotent/duplicate callbacks and retry after temporary failure (see [section 8](#8-reliability-scenarios))

---

## 1. Prerequisites

| Need | Version |
|---|---|
| PHP | 8.2 or newer with extensions `bcmath`, `mbstring`, `openssl`, `pdo_mysql` (or `pdo_pgsql`/`pdo_sqlite`), `fileinfo`, `ctype`, `tokenizer`, `xml` |
| Composer | 2.x |
| Database | MySQL 8+ **or** PostgreSQL **or** SQLite (zero install) |

## 2. Installation

```bash
git clone <your-repo-url> policyflow-<candidate-id>
cd policyflow-<candidate-id>
composer install
cp .env.example .env          # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
```

## 3. Database setup

**Option A: MySQL 8+ (default in `.env.example`)**

```sql
CREATE DATABASE policyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Then set `DB_USERNAME` / `DB_PASSWORD` in `.env`.

**Option B: SQLite (nothing to install)**

```bash
touch database/database.sqlite          # PowerShell: New-Item database/database.sqlite
```
In `.env` set `DB_CONNECTION=sqlite` and `DB_DATABASE=database/database.sqlite`, and delete the `DB_HOST/PORT/USERNAME/PASSWORD` lines.

## 4. Migrate (and seed)

```bash
php artisan migrate
```
There are no seeders: the system needs no reference data (plans, rates and multipliers are constants in
`App\Services\PremiumCalculator`; mock-insurer scenarios are chosen per request). Create data through the API.

## 5. Run the application

```bash
php artisan serve        # http://127.0.0.1:8000
```
Try the end-to-end walkthrough (needs `curl`, `jq` optional): `bash examples/api-examples.sh`

## 6. Run the tests

```bash
php artisan test         # or: composer test
```
Tests use an in-memory SQLite database (configured in `phpunit.xml`) so they need no setup.
To run them against MySQL instead: `DB_CONNECTION=mysql DB_DATABASE=policyflow_test php artisan test` (create that DB first).

## 7. API

All endpoints are under `/api`, send `Accept: application/json`. Errors always look like
`{"message": "...", "error": "<code>"}`; validation errors (422) are Laravel's standard
`{"message": "...", "errors": {"field": ["..."]}}`.

| Method | Path | Success | Notes |
|---|---|---|---|
| POST | `/api/policy-requests` | **201** created, **200** same `agent_id`+`client_request_id` already exists | 422 on invalid input |
| GET | `/api/policy-requests/{id}` | 200 | includes `attempts` and `history` (audit trail); 404 unknown |
| POST | `/api/policy-requests/{id}/submit` | **200** `ISSUED` · **202** still in progress · **502** `FAILED` | 409 if not allowed; 404 unknown |
| GET | `/api/policy-requests?status=&per_page=&page=` | 200 | `per_page` 1-100 (default 15); standard Laravel pagination (`data`, `links`, `meta`) |
| POST | `/api/mock-insurer/callback` | 200 | insurer event receiver, idempotent on `event_id` |
| GET | `/api/mock-insurer/policies/{key}` | 200 | mock status lookup, key = `PF-<request id>` |
| POST | `/api/mock-insurer/deliver-callbacks` | 200 | dev helper: makes the mock send its queued callbacks now |

### Create

```bash
curl -i -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"client_request_id":"AGT-2026-0001","agent_id":"AGENT-17","customer_name":"Sample Customer",
       "date_of_birth":"1998-04-10","destination":"INTERNATIONAL","trip_start":"2026-11-10",
       "trip_end":"2026-11-14","plan_code":"PLUS","sum_insured":300000}'
```
`201 Created` (a repeat of the same call returns `200 OK` + header `Idempotent-Replay: true` and `meta.created=false`):
```json
{
  "data": {
    "id": 1,
    "client_request_id": "AGT-2026-0001", "agent_id": "AGENT-17", "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10", "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10", "trip_end": "2026-11-14", "plan_code": "PLUS", "sum_insured": 300000,
    "status": "VALIDATED",
    "premium": "225.00", "currency": "INR",
    "premium_breakdown": {"travel_days": 5, "age_on_trip_start": 28, "base_daily_rate": "20.00",
                          "age_multiplier": "1.00", "destination_multiplier": "1.80", "coverage_multiplier": "1.25"},
    "policy_number": null, "provider_reference": null, "attempt_count": 0,
    "last_response": null, "last_error": null, "failure_retryable": false,
    "mock_scenario": "SUCCESS", "issued_at": null,
    "created_at": "2026-10-23T17:51:01+00:00", "updated_at": "2026-10-23T17:51:01+00:00"
  },
  "meta": {"created": true}
}
```
* `premium` is a **decimal string** (never a float). It is calculated once, stored as an immutable snapshot and never recomputed.
* Input rules: all nine fields required; dates `YYYY-MM-DD`; `trip_end >= trip_start`; `date_of_birth` not in the future and not after `trip_start`;
  `plan_code` ∈ `BASIC|PLUS|PREMIUM`; `destination` ∈ `DOMESTIC|INTERNATIONAL`; `sum_insured` ∈ `100000|300000|500000`.
* Optional, development only: `mock_scenario` ∈ `SUCCESS` (default) `TEMPORARY_FAILURE` `TIMEOUT_AFTER_ISSUE` `DELAYED_CALLBACK` `DUPLICATE_CALLBACK`.
  Ignored when `INSURER_ALLOW_SCENARIO_OVERRIDE=false` (set that in production).

### Submit

`POST /api/policy-requests/1/submit` → `200` with `data.status = "ISSUED"`, `policy_number`, `provider_reference`, `attempts[]`, `history[]`.
Calling it again is harmless: it returns the same issued policy and does **not** contact the insurer.
Other outcomes: `202` (`SUBMITTED` = another attempt is in flight, `PENDING_CONFIRMATION` = waiting for the insurer's callback,
`NEEDS_RECONCILIATION` = outcome unknown, will be resolved by lookup), `502` (`FAILED`; `failure_retryable` tells whether calling submit again will retry),
`409` `{"error":"issuance_not_allowed"}` (permanent failure or attempt limit reached).

### Callback

```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"event_id":"evt-1","provider_reference":"MOCK-REF-1030FAD1D2","type":"POLICY_ISSUED","policy_number":"POL-1030FAD1D2"}'
```
`200 {"data":{"event_id":"evt-1","result":"APPLIED|DUPLICATE|IGNORED","policy_request_id":4,"status":"ISSUED","policy_number":"POL-..."}}`.
`type` ∈ `POLICY_ISSUED` (needs `policy_number`) · `POLICY_FAILED` (optional `reason`). Optional `external_reference` = our key `PF-<id>`.
Unknown request → `404`; malformed → `422`.

More ready-to-run examples: [`examples/api-examples.sh`](examples/api-examples.sh).

---

## 8. Reliability scenarios

### Implemented and tested (all four in `tests/Feature`)

| # | Scenario | How it is handled | Tests |
|---|---|---|---|
| 1 | **Duplicate intake** (incl. near-simultaneous) | `UNIQUE(agent_id, client_request_id)` in the DB. Service looks up first, otherwise inserts; the loser of a race catches the unique violation and returns the winner's row. | `PolicyIntakeTest::test_repeated_client_request_id…`, `…database_unique_constraint…`, `…losing_the_insert_race…`, `…many_repeats…` |
| 2 | **Timeout after successful issue** | A timeout never means "not issued". The request goes `SUBMITTED → NEEDS_RECONCILIATION`, the insurer is asked by our idempotency key (`PF-<id>`), and the policy it already created is adopted. Re-issue happens **only** if the insurer positively says it has no record. | `IssuanceTest::test_timeout_after_issue_is_reconciled_without_issuing_twice`, `…stays_unresolved_while_lookup_is_down…`, `…reissue_happens_only_after…`, `…reconcile_command…` |
| 3 | Duplicate / delayed callbacks | `provider_events.event_id` is UNIQUE; event insert + state change are one transaction. A late `POLICY_FAILED` or a different policy number can never overwrite `ISSUED`. | `test_duplicate_callbacks_are_applied_exactly_once`, `test_callback_endpoint_is_idempotent_over_http`, `test_issued_state_is_never_overwritten…` |
| 4 | Retry after temporary failure | Failure is recorded (`FAILED`, `failure_retryable=true`); `submit` retries with a new attempt row up to `INSURER_MAX_ATTEMPTS`; the premium snapshot is immutable. | `test_temporary_failure_is_recorded_and_retry_succeeds_once…`, `test_retries_stop_at_the_attempt_limit` |

### Reproduce by hand

```bash
bash examples/api-examples.sh            # needs `php artisan serve` running; shows 201/200, 422, timeout, retry, duplicate callback
bash examples/parallel-race.sh 12        # real parallel PHP processes; WIPES the configured DB (migrate:fresh): use a scratch DB
php artisan policies:reconcile           # resolves NEEDS_RECONCILIATION and stale PENDING_CONFIRMATION/SUBMITTED
```
Mock insurer failure modes per request: create with `"mock_scenario": "TIMEOUT_AFTER_ISSUE"` (or any scenario above) and call submit.
To simulate "the insurer's status endpoint is down" set `MOCK_INSURER_LOOKUP_FAILS=true` in `.env`: a timed-out request then stays `NEEDS_RECONCILIATION` (and is **not** re-sent) until the flag is turned off and you call submit / `policies:reconcile`.

---

## 9. Architecture & key decisions

```
HTTP ─▶ Controller ─▶ FormRequest (validation) ─▶ Service ─▶ Models/DB
                                                    │
        PolicyIntakeService  : idempotent create + premium snapshot
        PremiumCalculator    : bcmath, pure function
        IssuanceService      : submit / reconcile / applyIssued (the workflow)
        ProviderCallbackProcessor : idempotent event application
        PolicyStateMachine   : the ONLY writer of `status` + audit history
                                                    │
                                          InsurerGateway (interface)
                                                    └─ MockInsurer (deterministic fake, own tables)
```

**States** (`App\Enums\PolicyStatus` holds the allowed transitions; everything else throws `InvalidStateTransition` → HTTP 409):

```
RECEIVED → VALIDATED → SUBMITTED ─┬→ ISSUED  (terminal, nothing can leave it)
                         ▲  ▲     ├→ PENDING_CONFIRMATION ─┬→ ISSUED
                         │  │     │                        ├→ FAILED
                 retry   │  │     ├→ NEEDS_RECONCILIATION ─┼→ ISSUED / PENDING_CONFIRMATION / FAILED
              FAILED ────┘  └─────┘        (insurer has no record) └→ SUBMITTED (safe re-issue)
                                  └→ FAILED  (temporary → retryable, rejected → permanent)
```
`RECEIVED` and `VALIDATED` are both written during intake (input was validated and priced in the same call).

**Tables**: `policy_requests` (state, premium snapshot, references), `issuance_attempts` (one row per insurer call),
`provider_events` (callbacks, UNIQUE `event_id`), `policy_state_history` (append-only audit), plus `mock_insurer_*` (the fake insurer's private data).

**Duplicate prevention**
* *Intake:* `UNIQUE(agent_id, client_request_id)`. App code is only an optimisation; the index decides.
* *Issuance:* (a) row lock + compare-and-swap on `status` means only one caller wins the `VALIDATED→SUBMITTED` move; a concurrent caller sees `SUBMITTED` and returns 202 without calling the insurer.
  (b) `UNIQUE(policy_request_id, attempt_number)`. (c) the same idempotency key `PF-<id>` is sent on every attempt so a real insurer could de-duplicate too (the mock does).
  (d) `UNIQUE(policy_number)` and `UNIQUE(provider_reference)` on `policy_requests`. (e) after any uncertainty we look up before re-sending.

**Transactions**: every state change is a short transaction that holds `SELECT … FOR UPDATE` on the request. The insurer is **never** called inside a transaction
(no network I/O while holding locks). The flow is: lock+record "attempt started" → commit → call insurer → lock+record outcome → commit.
A worker that dies mid-call leaves `SUBMITTED`; once older than `INSURER_STALE_SUBMITTED_SECONDS` the next submit/`policies:reconcile` reconciles it instead of re-sending.

**Mock insurer** (`App\Insurer\MockInsurer`): in-process, deterministic (policy number/reference derive from a hash of the key; behaviour depends only on scenario + attempt number).
`TEMPORARY_FAILURE` fails attempt 1 and succeeds on attempt ≥2. `TIMEOUT_AFTER_ISSUE` creates the policy then throws a timeout. `DELAYED_CALLBACK`/`DUPLICATE_CALLBACK` answer "pending" and queue one event
(delivered once / three times) when `deliver-callbacks` is called. It also dedupes on the idempotency key, like a good insurer.

## 10. Assumptions, limitations, not implemented

* **Premium rounding:** implemented as round-half-up on a bcmath string product. With the specified multipliers the exact product never has more than 2 decimals, so the rounding step never changes a value today; it is there for rule changes.
* **Age** = completed years on `trip_start`. **Past trip dates are accepted** (spec only requires `trip_end >= trip_start`); `date_of_birth` must not be in the future or after `trip_start`.
* **Replaying a key with a different body** returns the original request unchanged (200). It is not an error and never changes the stored premium. A stricter API could return 409 when the body differs.
* **Mock is in-process**, not a separate HTTP server, to avoid self-calls deadlocking `php artisan serve` (single-threaded). Mock callbacks call the same `ProviderCallbackProcessor` as the HTTP endpoint (the endpoint is tested separately over HTTP).
* **No authentication / callback signature verification** (out of scope; a real integration needs an HMAC-signed callback and agent auth). Customer name/DOB are stored (needed for the mock), never logged and never sent to the insurer payload.
* **Concurrency was verified on SQLite only** (in-memory tests plus `examples/parallel-race.sh` with real parallel processes). SQLite serialises writers, so it cannot show row-lock contention. The MySQL/PostgreSQL locking code paths (`lockForUpdate`, `INSERT IGNORE`/`ON CONFLICT DO NOTHING`) are standard Laravel but were **not executed in this repository's test runs** (no MySQL available while developing).
* No queue/async processing: `submit` does the insurer call synchronously. Not implemented (optional): CSV bulk intake, queues with backoff, Filament dashboard, load test.
* Scheduling: `policies:reconcile` is a manual command; add `Schedule::command('policies:reconcile')->everyFiveMinutes();` in `routes/console.php` to run it periodically.

### How the other reliability cases would be solved
Already covered: duplicate callbacks and retry after temporary failure (section 8). For production I would add: a queue with exponential backoff + dead-letter table for `submit`,
a scheduled `policies:reconcile`, HMAC callback verification, and per-agent rate limiting.

## 11. Repository map

```
app/Enums            PolicyStatus (transition table), MockScenario
app/Exceptions       domain exceptions (mapped to 409 in bootstrap/app.php)
app/Http             controllers, form requests (validation), API resource (response format)
app/Insurer          InsurerGateway interface, MockInsurer, result DTOs
app/Services         PremiumCalculator, PolicyIntakeService, IssuanceService, ProviderCallbackProcessor, PolicyStateMachine
app/Console          policies:reconcile
config/insurer.php   attempt limits, staleness, mock switches
database/migrations  all tables
tests/               Unit (pricing, transition table) + Feature (intake, issuance, reliability)
examples/            curl walkthrough, parallel race scripts
```
