# Teami AI Enterprise

Standalone Laravel 12 application for Teami AI Enterprise. It is separate from MaxCon ERP; no MaxCon files or database are used.

## Company advisor development

The new product roadmap is [ADVISOR-ROADMAP.md](docs/ADVISOR-ROADMAP.md). Its first implementation adds permission-protected CSV/XLSX previews with column mapping and explicit transactional imports for employees, customers, historical sales invoices and receipts. Read [ADVISOR-IMPORTS.md](docs/ADVISOR-IMPORTS.md). Initial sales/collection metrics and invoice evidence are available under advisor.sales.read. Returns, exact-period targets, annual compensation imports and a first deterministic bonus policy/review/independent-decision cycle are now included; read [ADVISOR-BONUS.md](docs/ADVISOR-BONUS.md). Saved sales/HR reports, opt-in daily/weekly/monthly schedules and reference-validated qualitative AI explanations are also included; read [ADVISOR-REPORTS.md](docs/ADVISOR-REPORTS.md). Confirmed source coverage, direct connectors, advanced policies and live staging acceptance remain pending.

## Searchable selections and external advisory references

All current business selection fields use a shared searchable Fluent Combobox. An opt-in, activity-aware catalog of reviewed external summaries supports policies, sales, team and customer guidance. Sources remain distinct from company policy. Read [EXTERNAL-REFERENCES.md](docs/EXTERNAL-REFERENCES.md). Live crawling, automatic updates and Iraqi legal compliance are not implemented.

## Release candidate

Operational workflows, manual-transfer subscriptions, membership administration, account recovery and deployment templates are included. Read [the launch guide](docs/LAUNCH.md) before production. Live hosting, MySQL concurrency, SMTP delivery, AI provider acceptance and backup restoration still require staging verification.

## Requirements

- Node 20.19+ and npm for frontend development/builds.

- PHP 8.4.1+ for the supplied composer.lock, with the extensions required by Laravel and the selected database driver. The root composer.json allows 8.2+, but several locked Symfony packages require 8.4.1+.
- Composer 2.
- MySQL 8+ for production; SQLite is configured for the local test suite.
- Redis is recommended for production cache and queues. The database queue is available as a simpler local option.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Set `AUDIT_HMAC_KEY` to a randomly generated 32-byte or longer secret, for example the hex output from `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Store it in the deployment secret manager and keep it stable for verification of existing audit records. Never commit `.env` or this key.

Configure the database and Redis, then run:

```sh
php artisan migrate --force
php artisan db:seed --force
npm ci
npm run build
php artisan serve
php artisan queue:work --queue=critical,ai,default
php artisan schedule:work
```

Configure production workers to include the queue names needed by deployed features. The `analytics` queue generates opted-in advisor reports. The `rag`, `documents`, `notifications`, and `billing` queues are reserved for later phases and do not represent implemented features. Phase 3 uses the `ai` queue for knowledge indexing.

## Authentication and tenant context

The root path (`/`) serves the Arabic RTL browser workspace. It uses the existing API login and Sanctum token flow, stores the session token in browser local storage, and sends the selected tenant in `X-Tenant`. Dashboard, employee, customer, opportunity, payroll, knowledge, and billing views request data from the existing tenant-protected API. Employees and customers can be created from the UI; employees can also be searched, filtered, paginated, viewed and updated. The leave workspace supports requests, decisions, type creation, balance allocation and balance lookup, with controls shown only for the corresponding permissions; those writes use idempotency keys, tenant permissions, audit records, and outbox events. The browser UI uses Microsoft Fluent UI React v9 and a Laravel Vite build. Run `npm ci` and `npm run build` when modifying source; built assets are included in the delivered ZIP. See `docs/FLUENT-UI.md` for layout and frontend verification. Some workflows still require API support and are identified in the implementation boundaries below.

The API login requires `email`, `password`, and a tenant UUID or slug. It returns a Sanctum bearer token only after verifying the active user and active tenant membership. Authenticated tenant routes send the selected tenant identifier in `X-Tenant`; every request validates active membership and tenant status.

Password reset uses `POST /api/auth/forgot-password` and `POST /api/auth/reset-password`; responses avoid disclosing whether an email has an account. Configure the mail transport before enabling this in a deployed environment.

MFA can be enrolled using the authenticated `/api/auth/mfa/setup` and `/api/auth/mfa/confirm` routes. Setup returns a TOTP secret once; confirmation returns one-time recovery codes. Store them securely. Login for an MFA-enabled account requires `mfa_code` (a TOTP code or unused recovery code).

Create the first tenant and owner from a trusted terminal with `php artisan teami:tenant:create {slug} {name} {owner_email} {owner_name}`. The command prompts for a strong password without echoing it, assigns the owner permissions, and writes an audit record.

The context endpoint is `GET /api/tenant/context`. Do not treat a tenant identifier from a request as authorization; membership checks remain mandatory.

## Security foundations

- Tenant repositories require an active `TenantContext`; do not use unscoped model queries for tenant-owned data.
- Cache keys and private storage paths are tenant-prefixed. File downloads must also perform authorization before serving the stored path.
- Audit records are append-only through the model, HMAC chained per tenant, and redacted. Direct database administration can still alter records; `AuditTrailService::verifyChain()` detects chain changes when the HMAC key is available.
- Outbox delivery is at-least-once. Consumers must deduplicate by `event_uuid`.
- Idempotency middleware is opt-in and intended for authenticated tenant routes. It stores successful JSON responses and rejects reuse of a key with a different request body.
- Webhook inbox signatures use lowercase or uppercase hexadecimal HMAC-SHA256 over the canonical JSON payload. The shared secret is supplied by the caller and is never persisted.

## API reference

See [docs/API.md](docs/API.md) for authenticated endpoints, permissions, request flows, and current implementation boundaries.

## Phase 2: HR and Sales core

The additive HR and Sales migration adds tenant-bound departments, positions, employees, encrypted employee identifiers, compensation, attendance, leave balances and requests, performance cycles/reviews, goals, recruiting, employee-relations cases, customers, contacts, leads, sales pipelines/stages, opportunities, activities, targets, and forecasts. Tenant-owned references use composite tenant keys where supported by the schema.

Authenticated APIs are available under `/api/hr` and `/api/sales`:

- HR: departments, positions, employees, leave types/balances/requests/decisions, encrypted compensation records, attendance, performance cycles/goals/reviews, requisitions/candidates/applications, and employee-relations cases.
- Sales: customers, contacts, leads, pipelines/stages, opportunities, activities, targets, and generated forecasts.

All endpoints require active tenant membership and their matching seeded permission (for example `hr.employee.read`, `hr.leave.approve`, `sales.customer.create`, or `sales.opportunity.write`). Sensitive compensation, review, candidate, interview, employee-relations, and payroll reads additionally require `data.sensitive.read`. Performance review decisions require the separate `hr.performance.approve` permission and prevent self-approval. Converting a hired candidate to an employee requires both `hr.recruitment.write` and `hr.employee.create`; onboarding task routes use `hr.onboarding.read` and `hr.onboarding.write`. Payroll permissions separate preparation, approval, and recording payment. The payroll ledger snapshots annual base compensation as monthly base pay and supports manually entered allowances/deductions; it does not calculate statutory taxes or transfer funds. Send the tenant UUID in `X-Tenant`. Use public UUIDs for related records; do not send internal database IDs. Employee resources omit personal contact, birth date, and encrypted identifiers. HR now includes recruitment stages/interviews, review approval, employee onboarding, and internal payroll preparation/review; broader workforce lifecycles and browser screens remain out of scope. See `docs/API.md` for endpoint details.

## Verification

```sh
php artisan test
./vendor/bin/pint --test
composer audit
```

The GitHub Actions workflow at `.github/workflows/backend-integration.yml` runs the full test suite against MySQL 8 and Redis, including the tenant cache isolation check. Local PHPUnit defaults to in-memory SQLite and an array cache so the suite can run without external services.

## Phase 3: knowledge RAG and AI agents

Tenant-scoped knowledge sources and documents are stored with encrypted text. Text ingestion is queued on `ai`, split into overlapping chunks, and embedded through an OpenAI-compatible provider. `/api/ai/ask` retrieves matching chunks from the current tenant, limits sensitive sources to users with `ai.knowledge.sensitive.read`, and returns the answer with document citations. Conversations and messages are tenant-bound and encrypted; each follow-up includes a bounded recent message history. Per-tenant agent profiles can restrict which source UUIDs they can retrieve and add system instructions. The initial agent runtime is read-only: it does not invoke tools or perform business mutations.

Configure `AI_API_ENDPOINT` (an OpenAI-compatible base such as a provider's `/v1` endpoint), `AI_API_KEY`, `AI_CHAT_MODEL`, and `AI_EMBEDDING_MODEL` to enable provider calls. User questions, indexed document chunks, and configured agent instructions are sent to that configured provider during embedding and answer generation; choose a provider and data handling terms appropriate for the tenant. The default installation makes no AI network calls until configured. The initial cosine search scans up to 5,000 tenant chunks in application code; production scale requires a vector database or database-native vector index. Queued documents need a worker listening to the `ai` queue.

Manual-transfer billing now issues subscription invoices, reviews reported payments with separation of duties and enforces member/AI limits. Automatic bank collection, taxes and refunds remain outside this release. See docs/LAUNCH.md for the implemented workflows and production acceptance gates.

## HR workspace update (2026-10-04)

- Employee PATCH requires `hr.employee.update`; changing the employment status to `terminated` records the termination date, and reactivation clears it. This does not revoke the associated user account or tenant membership.
- Employee reads accept `search`, `status`, `page`, and `per_page` (maximum 100). The browser uses server pagination.
- Leave balance reads require `hr.leave.read` and an employee UUID plus year. Available days exclude used and pending days.
- The leave UI displays employee choices to users with `hr.employee.read`. Approval requires a reviewer with an active employee record linked by `user_id`. The existing workflow permits self-approval; no new approval policy is imposed in this update.
- Mutations in the HR workspace use idempotency keys and preserve the existing tenant, audit and outbox layers.
- Existing permissions are returned by `/api/tenant/context` so controls reflect current authorization. API permission middleware remains authoritative.
- AI configuration prefers `AI_API_KEY`, with `OPENAI_API_KEY` as a fallback. Set `AI_API_ENDPOINT` and model names as described above. No provider call was made while developing this update.

See `docs/UPDATE-INSTRUCTIONS.md` before installing this update over an existing local project.

## Fluent UI interface update

The current workspace uses real Fluent UI React v9 components, Fluent icons, Arabic RTL, light/dark themes, responsive navigation and Fluent form dialogs. Existing employee/customer creation and HR workflows are preserved. Dashboard metrics are sourced from API metadata. The AI screen renders document citations alongside answers. Read `docs/FLUENT-UI.md` for design, source locations and frontend checks.

بوابة التكاملات ومصادر البرامج: [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md). تستقبل JSON موحّداً؛ المحوّلات الأصلية لكل برنامج أعمال لاحقة.
