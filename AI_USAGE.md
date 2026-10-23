# AI Usage Disclosure

## 1. Tools Used

- **Claude Code (Anthropic):** Used to assist with implementation, testing, debugging, code review, and documentation in the local project workspace.
- **ChatGPT (OpenAI):** Used for assignment planning, implementation guidance, and submission preparation.
- **Official documentation:** Laravel, PHP, Composer, and related framework documentation were consulted for technical reference.

## 2. How AI Assisted

1. **Application implementation:** Claude Code assisted with the Laravel project structure, database migrations, models, premium calculation, state-machine logic, intake and issuance services, callback processing, mock insurer, controllers, API resources, and routes.

2. **Automated testing:** AI assisted in designing and writing PHPUnit tests for the assignment's acceptance criteria, including pricing boundaries, validation failures, duplicate intake, policy issuance, retries, callbacks, and reconciliation behavior.

3. **Debugging and engineering review:** AI-assisted review identified implementation issues involving framework compatibility, PSR-4 autoloading, test assertions, database uniqueness, transaction boundaries, and concurrent request handling.

4. **Documentation:** AI assisted in preparing the README, API examples, race-test scripts, and technical explanations of the application's architecture, assumptions, and limitations.

## 3. Incorrect or Incomplete Suggestions Encountered

Several issues were identified and corrected during development:

- **Framework compatibility:** The initial scaffold used Laravel 13, which requires PHP 8.3. The project was changed to Laravel 12 to maintain compatibility with PHP 8.2 or newer.
- **Autoloading:** An initial draft placed multiple exception classes in one file. The classes were separated into individual files to follow PSR-4 autoloading conventions.
- **Test quality:** An initial reconciliation test lacked meaningful assertions. It was removed and replaced with tests intended to verify actual behavior.
- **Concurrency test design:** An initial race-test approach did not accurately model the database conflict because the competing insert was inside a transaction that rolled back. The test approach was revised to exercise the unique-constraint failure path.
- **Database verification limitations:** Concurrency testing was performed with SQLite. MySQL and PostgreSQL behavior was not independently verified in the development environment.

These corrections illustrate why AI-generated code and test designs require inspection and execution rather than being accepted without verification.

## 4. Verification Performed

The following checks were reported during development:

- **Automated tests:** `php artisan test` passed 72 tests with 353 assertions on SQLite.
- **Mutation check:** Temporarily permitting an invalid `ISSUED → SUBMITTED` transition caused two tests to fail. The temporary change was reverted.
- **HTTP testing:** The application was served locally, and `examples/api-examples.sh` exercised request creation, duplicate requests, validation errors, successful issuance, timeout-after-issue handling, retry behavior, and repeated callbacks.
- **Concurrency testing:** `examples/parallel-race.sh` used separate PHP processes against a SQLite database file. The reported results were one logical request from eight simultaneous intake attempts and one insurer-side policy with one attempt from eight simultaneous timeout-scenario submissions.
- **Formatting:** Laravel Pint was run, followed by another automated test run.
- **Clean setup:** A clean export of the committed files was used to install dependencies, run migrations, and execute the test suite successfully.

## 5. Limitations and Remaining Verification

- MySQL and PostgreSQL execution was not verified.
- SQLite does not reproduce all locking and concurrency behavior of MySQL or PostgreSQL.
- Authentication and callback-signature verification are not implemented.
- Issuance processing is synchronous; queue-based processing is not implemented.
- Additional environment-specific testing may be required before production use.

The implementation is intended as a practical assessment solution, not a production-ready insurance platform.

AI tools were used as development aids. The submitted implementation should be reviewed by the candidate, and all claims about testing and verification should reflect the results actually observed.