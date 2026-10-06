# Launch candidate verification — 2026-10-05

## Executed in the development environment

- PHP 8.4.23; Laravel feature/unit suite: **45 tests, 322 assertions**, SQLite testing database.
- Covers tenant isolation, permissions, manual transfer amount/reference/overlap, independent approval, duplicate/replayed requests, encrypted idempotency responses, seat and AI limits, suspended memberships, employee linking, compensation periods, failed indexing retry, password-reset route and security headers.
- `npm run build`: successful, production manifest and hashed JS/CSS included.
- Laravel Pint for app/bootstrap/config/database/routes/tests: passed.
- `bash -n deploy/release.sh`, billing route inventory and scheduler inventory: passed.
- `npm audit --omit=dev --audit-level=high`: zero known runtime dependency vulnerabilities at verification time.
- Playwright: **10 scenarios passed**, including exact billing amount conversion, transfer reporting, selected membership grants, contact linking, login/MFA, leave workflows, retries and mobile layout.
- Browser tests use the real built Laravel frontend with intercepted fixture API responses; backend behavior is tested separately. Fixtures contain no production records or credentials.

## Required production acceptance

MySQL/Redis integration workflow is supplied but has not run on the user's repository. SMTP delivery, real AI indexing/answers, hosting/TLS, concurrent requests on MySQL, queue monitoring and backup restoration remain unverified here. The deployment templates have syntax checks, not a deployment acceptance test. See LAUNCH.md for the gate before customers are admitted.

This archive carries no .env, live database, uploaded private files, vendor directory or node_modules. Install locked dependencies on the target machine. Keep the existing encryption and audit keys during upgrades.
