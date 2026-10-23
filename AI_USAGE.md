# AI usage disclosure

## 1. Tools used

* **Claude** (Anthropic), used through the Claude Code desktop app (model: Claude Sonnet 5.5), working directly in this repository's local workspace.
* No other AI tool (no Copilot, ChatGPT or Cursor) was used for this project.
* Ordinary documentation (Laravel, PHP, Composer) was consulted for framework behaviour.

## 2. How AI helped (concretely)

1. **Scaffolding and first implementation.** Claude read the assignment document, scaffolded the Laravel project, and wrote the first versions of the migrations, models,
   `PremiumCalculator`, `PolicyStateMachine`, `PolicyIntakeService`, `IssuanceService`, `ProviderCallbackProcessor`, the mock insurer, controllers and the API resource.
2. **Test design and writing.** Claude proposed the test list from the assignment's acceptance criteria and wrote the 72 PHPUnit tests, including the age-boundary data sets, the "insurer called exactly once" assertions (via a counting subclass of the mock) and the simulated insert-race test.
3. **Documentation and review.** Claude drafted the README, the curl walkthrough and the parallel-race scripts, and reviewed its own code for state-machine regressions and lock/transaction placement.

## 3. Incorrect or incomplete AI output encountered (and fixed)

* **Wrong framework version.** The first scaffold came out as Laravel 13, which requires PHP 8.3, while the assignment says PHP 8.2+/Laravel 11+. It was downgraded to Laravel 12 (`php ^8.2`) and dependencies were re-resolved.
* **Autoload bug.** The first draft of the exceptions put six classes in a single file, which PSR-4 autoloading cannot find. It was split into one class per file before anything ran.
* **Meaningless test.** A draft test (`..._reconciled_when_stale`) had no assertions and would always pass; it was deleted and replaced by two real tests (`test_submit_while_another_attempt_is_in_flight…`, `test_stale_submitted_request_is_reconciled…`).
* **Flawed race test idea.** The first idea for the "lost insert race" test inserted the competing row inside the same transaction, which the rollback would have erased. It was replaced by a subclass whose first lookup returns `null`, so the real unique-index failure is exercised.
* **Overstated verification risk.** Concurrency was only exercised on SQLite; the README states plainly that MySQL/PostgreSQL locking was not executed here.

## 4. How the output was verified

* `php artisan test`: 72 tests / 353 assertions passing on SQLite in-memory (run after the final formatting pass).
* **Mutation check:** the transition table was temporarily changed to allow `ISSUED → SUBMITTED`; 2 tests failed as expected, then the change was reverted. (Disabling the mock insurer's own idempotency was *not* caught by tests, intentionally: the application does not rely on it.)
* **Real HTTP:** the app was served with `php artisan serve` and `examples/api-examples.sh` was run against it (201/200, 422, success, timeout-after-issue, temporary-failure retry, triple callback).
* **Real parallelism:** `examples/parallel-race.sh` (separate PHP processes against a SQLite file): 8 simultaneous intakes → 1 row; 8 simultaneous submits of a `TIMEOUT_AFTER_ISSUE` request → 1 insurer-side policy, 1 attempt.
* Code formatted with Laravel Pint.
* **Not verified:** execution against MySQL or PostgreSQL (none installed in the development environment).
