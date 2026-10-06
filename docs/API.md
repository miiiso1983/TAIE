# Teami API guide

All business routes use Sanctum bearer authentication, an active tenant membership, an active tenant, and route-level permissions. Send `X-Tenant: <tenant UUID>` on each request. Use public UUIDs for business records; never send database primary keys.

## Authentication

Create the first tenant and owner from a trusted terminal:

```sh
php artisan teami:tenant:create acme "Acme Ltd" admin@example.com "Tenant Admin"
```

Login with `POST /api/auth/login` and JSON `{ "email": "...", "password": "...", "tenant": "<tenant UUID or slug>" }`. Send the returned token as `Authorization: Bearer <token>`. If MFA is enabled, provide `mfa_code`.

## HR

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET / POST | `/api/hr/departments` | `hr.department.read` / `hr.department.manage` | List or create a department |
| GET / POST | `/api/hr/positions` | `hr.position.read` / `hr.position.manage` | List or create a position |
| GET / POST | `/api/hr/employees` | `hr.employee.read` / `hr.employee.create` | List or create employees; create accepts an optional `Idempotency-Key` header |
| GET | `/api/hr/employees/{uuid}` | `hr.employee.read` | Read one tenant employee |
| GET / POST | `/api/hr/leave-types` | `hr.leave.read` / `hr.leave.manage` | List or create leave types |
| PUT | `/api/hr/leave-balances` | `hr.leave.manage` | Set one employee's annual allocation |
| GET | `/api/hr/leave-requests` | `hr.leave.read` | List tenant leave requests, optionally filtered by `status` |
| POST | `/api/hr/leave-requests` | `hr.leave.create` | Submit a request; policy can require approval or auto-approve |
| POST | `/api/hr/leave-requests/{uuid}/decision` | `hr.leave.approve` | Approve or reject a pending request |
| GET / POST | `/api/hr/employees/{uuid}/compensation` | GET: `hr.compensation.read` + `data.sensitive.read`; POST: `hr.compensation.write` | Read or add an encrypted compensation period; overlapping periods are rejected |
| GET / POST | `/api/hr/attendance` | `hr.attendance.read` / `hr.attendance.write` | Filter attendance by employee and date, or record/update a daily shift |
| GET / POST | `/api/hr/performance/cycles` | `hr.performance.read` / `hr.performance.write` | List or create performance cycles |
| PATCH | `/api/hr/performance/cycles/{uuid}/status` | `hr.performance.write` | Complete a cycle after every review is approved, then archive it |
| GET / POST | `/api/hr/performance/goals` | `hr.performance.read` / `hr.performance.write` | Filter or create employee goals, optionally linked to a cycle |
| GET / POST | `/api/hr/performance/reviews` | GET: `hr.performance.read` + `data.sensitive.read`; POST: `hr.performance.write` | Read sensitive reviews or create a review |
| POST | `/api/hr/performance/reviews/{uuid}/decision` | `hr.performance.approve` + `data.sensitive.read` | Approve or return a submitted review; the original reviewer cannot approve it |
| PATCH | `/api/hr/performance/reviews/{uuid}` | `hr.performance.write` + `data.sensitive.read` | Revise and resubmit a review returned for revision; only its original reviewer may edit it |
| GET / POST | `/api/hr/requisitions` | `hr.recruitment.read` / `hr.recruitment.write` | List or create job requisitions |
| GET / POST | `/api/hr/candidates` | GET: `hr.recruitment.read` + `data.sensitive.read`; POST: `hr.recruitment.write` | Search candidates or add a candidate |
| POST | `/api/hr/applications` | `hr.recruitment.write` | Create an application for an open requisition; duplicate applications return the existing record |
| GET | `/api/hr/applications` | `hr.recruitment.read` + `data.sensitive.read` | List applications, optionally filtered by status |
| PATCH | `/api/hr/applications/{uuid}/status` | `hr.recruitment.write` | Advance an application through applied → screening → interview → offered → hired, or reject/withdraw it |
| POST | `/api/hr/applications/{uuid}/employee` | `hr.recruitment.write` + `hr.employee.create` | Convert a hired application into an employee; accepts `employee_number`, optional `work_email` and `manager_uuid`, and required `hired_at`. Name/contact and default department, position, and employment type come from the candidate and requisition. Repeated calls return the linked employee |
| GET / POST | `/api/hr/employees/{employeeUuid}/onboarding-tasks` | `hr.onboarding.read` / `hr.onboarding.write` | List or create tenant-scoped, employee-linked onboarding tasks; optional assignees must be active tenant members |
| PATCH | `/api/hr/onboarding-tasks/{uuid}` | `hr.onboarding.write` | Move a task from pending to in progress, completed, or waived; completed tasks record who completed them |
| GET / POST | `/api/hr/payroll/runs` | GET: `hr.payroll.read` + `data.sensitive.read`; POST: `hr.payroll.write` + `hr.compensation.read` + `data.sensitive.read` | List or create a monthly draft from active/on-leave employees employed for the whole month with compensation covering the full month in the selected currency |
| GET | `/api/hr/payroll/runs/{uuid}` | `hr.payroll.read` + `data.sensitive.read` | View the encrypted payroll snapshot and employee line items |
| PATCH | `/api/hr/payroll/runs/{runUuid}/items/{itemUuid}` | `hr.payroll.write` + `data.sensitive.read` | Set nonnegative manual allowances/deductions while the run is a draft; net pay cannot be negative |
| POST | `/api/hr/payroll/runs/{uuid}/submit` | `hr.payroll.write` + `data.sensitive.read` | Submit a draft for review |
| POST | `/api/hr/payroll/runs/{uuid}/return` | `hr.payroll.approve` + `data.sensitive.read` | Return a run under review to draft with a required correction reason |
| POST | `/api/hr/payroll/runs/{uuid}/approve` | `hr.payroll.approve` + `data.sensitive.read` | Approve a reviewed run; the preparer cannot approve their own run |
| POST | `/api/hr/payroll/runs/{uuid}/mark-paid` | `hr.payroll.disburse` + `data.sensitive.read` | Record that an approved run has been paid; the approver cannot mark it paid |
| GET / POST | `/api/hr/applications/{applicationUuid}/interviews` | GET: `hr.recruitment.read` + `data.sensitive.read`; POST: `hr.recruitment.write` | List or schedule interviews for an application in the interview stage |
| PATCH | `/api/hr/interviews/{uuid}` | `hr.recruitment.write` | Complete a scheduled interview with score and feedback, or cancel it |
| GET / POST | `/api/hr/employee-relations/cases` | GET: `hr.employee_relations.read` + `data.sensitive.read`; POST: `hr.employee_relations.write` | Read or open a confidential case |
| PATCH | `/api/hr/employee-relations/cases/{uuid}` | `hr.employee_relations.close` | Change a case status to open or closed |

Employee create accepts related department, position, and manager UUIDs. Leave requests require `employee_uuid`, `leave_type_uuid`, `starts_on`, `ends_on`, and `requested_days`. Balance setup accepts employee/type UUIDs, year, and allocated days. Existing used and pending days cannot be reduced by changing the allocation. Overlapping pending or approved requests are rejected.

## Sales

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET / POST | `/api/sales/customers` | `sales.customer.read` / `sales.customer.create` | Search or create customers; create accepts an optional `Idempotency-Key` header |
| GET / POST | `/api/sales/customers/{customerUuid}/contacts` | `sales.contact.read` / `sales.contact.write` | List or create a customer's contacts |
| GET / POST | `/api/sales/leads` | `sales.lead.read` / `sales.lead.write` | List or create leads |
| GET / POST | `/api/sales/pipelines` | `sales.opportunity.read` / `sales.pipeline.manage` | List or create pipelines with stages |
| GET / POST | `/api/sales/opportunities` | `sales.opportunity.read` / `sales.opportunity.write` | List or create opportunities |
| GET | `/api/sales/opportunities/{uuid}` | `sales.opportunity.read` | Read one opportunity |
| POST | `/api/sales/opportunities/{uuid}/stage` | `sales.opportunity.write` | Move an open opportunity to a stage |

Opportunity creation takes customer, pipeline, and stage UUIDs. Pipeline creation takes an ordered `stages` array with `name`, `probability`, and optional `is_won`/`is_lost` flags.

## Sales operations

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET / POST | `/api/sales/activities` | `sales.activity.read` / `sales.activity.write` | Filter or create an activity linked to a customer, contact, lead, or opportunity |
| PATCH | `/api/sales/activities/{uuid}` | `sales.activity.write` | Complete or cancel an open activity |
| GET / POST | `/api/sales/targets` | `sales.target.read` / `sales.target.write` | List or create sales targets |
| PATCH | `/api/sales/targets/{uuid}` | `sales.target.write` | Update target actual value and optional status |
| GET | `/api/sales/forecasts` | `sales.forecast.read` | List generated forecasts |
| POST | `/api/sales/forecasts/generate` | `sales.forecast.write` | Generate/update a forecast from open opportunities in the requested date range and currency |

## Billing foundation

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET | `/api/billing/subscription` | `billing.subscription.read` | Read the current tenant's active/trialing subscription and plan, or `null` when none is assigned |
| GET | `/api/billing/usage` | `billing.usage.read` | Read tenant usage totals and records; optional `from`, `to`, and `metric` filters |
| POST | `/api/billing/usage` | `billing.usage.write` | Record a positive usage quantity with a tenant/metric-scoped `idempotency_key` |

Billing plan amounts use integer minor currency units. Usage records are isolated by tenant and deduplicated for each tenant, metric, and idempotency key. The launch stage adds manual invoice issuance, verified transfers, subscription activation and plan-limit enforcement. Automatic tax, bank collection, refunds and provider-specific webhook processing remain unimplemented. The shared webhook inbox remains provider-neutral and is not wired to billing state transitions.

## Knowledge and AI

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| GET / POST | `/api/knowledge/sources` | `ai.knowledge.read` / `ai.knowledge.manage` | List or create knowledge sources |
| POST | `/api/knowledge/sources/{sourceUuid}/documents` | `ai.knowledge.manage` | Add and queue a text document for indexing |
| GET | `/api/knowledge/documents/{uuid}` | `ai.knowledge.read` | Check indexing status without exposing document text |
| POST | `/api/ai/agents` | `ai.agent.manage` | Create an agent profile with instructions and optional source allowlist |
| POST | `/api/ai/ask` | `ai.ask` | Ask a grounded question; accepts optional `agent_uuid` and `conversation_uuid` |
| GET | `/api/ai/conversations` | `ai.ask` | List the current user's conversations |
| GET | `/api/ai/conversations/{uuid}/messages` | `ai.ask` | Read messages from the current user's conversation |

AI routes require `AI_API_ENDPOINT`, `AI_API_KEY`, and model names in the environment. A worker must consume the `ai` queue. Confidential and restricted sources require `ai.knowledge.sensitive.read`. AI calls transmit the user's question and retrieved source passages to the configured provider. The current agent runtime is read-only and does not run business tools.

## Current implementation boundary

The application includes an Arabic Fluent UI workspace alongside the API backend. HR supports an initial application stage and interview flow, review resubmission and approval, cycle closure, explicit employee creation from a hired application, onboarding tasks, and an internal monthly payroll preparation/review ledger. Automatic user-account provisioning, interview panel coordination/notifications, statutory tax and deduction calculations, bank transfers, and other advanced workflows remain outside this implementation. AI indexing accepts text content; PDF/Office extraction and a production vector database are not included. See `README.md` for setup and operational caveats.

## HR workspace additions (2026-10-04)

- `PATCH /api/hr/employees/{uuid}` — `hr.employee.update`; partial update of employee number, names, work email, employment status/type, hire date, department UUID and position UUID. Null clears optional fields. An existing position must match the resulting department; clear or replace it when changing departments. Tenant references and employee-number uniqueness are checked. The operation emits `hr.employee.updated` audit/outbox records.
- `GET /api/hr/employees` supports `search` (first/last name, employee number, work email), `status`, `page`, and `per_page` between 1 and 100.
- `GET /api/hr/leave-balances?employee_uuid={uuid}&year={year}` — `hr.leave.read`; returns public employee/type UUIDs, type name, year, allocated/used/pending/available days. Unknown or foreign employee UUIDs return 404; no configured balances returns an empty array.
- `/api/tenant/context` additionally returns `data.permissions` for the selected tenant.
- Employee PATCH, leave type creation, balance allocation, leave submission and decisions accept `Idempotency-Key` using the existing middleware.
- Leave decisions return 422 with an explanatory message when the reviewer has no active employee record linked to their account.


## Launch-stage endpoints

All routes below require the active tenant context and membership; write requests support Idempotency-Key unless noted.

| Method/path (under /api) | Permission | Purpose |
|---|---|---|
| GET identity/members, identity/permissions | identity.membership.manage | Members / actor-delegable grants |
| POST identity/members | identity.membership.manage | New user with strong initial password |
| PATCH identity/members/{uuid} | identity.membership.manage | Status/grants; self and protected accounts excluded |
| PATCH identity/members/{uuid}/employee | identity.membership.manage + hr.employee.update | Link unique active member to employee |
| POST auth/password | authenticated | Current password + confirmed new password; revokes tokens |
| GET billing/plans | billing.subscription.read | Active plan catalogue |
| POST billing/plans | platform.billing.manage | Price in integer minor units and user/AI limits |
| GET billing/invoices, billing/payments | billing.invoice.read | Scoped paginated records |
| POST billing/invoices | platform.billing.manage | Pending subscription and invoice for plan_uuid, starts_at, due_at |
| POST billing/invoices/{uuid}/payments | billing.payment.report | reference and exact amount_minor |
| POST billing/payments/{uuid}/decision | platform.billing.manage | approve/reject + required note; separate reviewer |
| POST billing/invoices/{uuid}/void | platform.billing.manage | Open invoice without pending transfer |
| GET knowledge/sources/{uuid}/documents | ai.knowledge.read | Scoped documents and indexing states |
| POST knowledge/documents/{uuid}/retry | ai.knowledge.manage | Requeue a failed document |
| PATCH sales/customers/{uuid} | sales.customer.update | Audited update |

Manual billing uses USD cent (2 decimals) / IQD fils (3 decimals). UI accepts currency amounts for plans and converts exactly. No partial payment, automatic tax, bank integration or refund workflow. BILLING_ENFORCE must be true for production quotas and subscription gating. Idempotent response bodies are encrypted at rest and keys bind to the concrete record path.

## Advisor data imports

See [ADVISOR-IMPORTS.md](ADVISOR-IMPORTS.md) for multipart preview and transactional commit schemas, limits and permission requirements. Imported financial data is for future business analysis, not billing subscription invoices or accounting postings.

## XLSX mapping and initial metrics

Advisor import inspect/preview now accept CSV and XLSX. Mapping, sheet selection and safety limits are documented in [ADVISOR-IMPORTS.md](ADVISOR-IMPORTS.md). Protected GET advisor/sales/metrics and advisor/sales/invoices require advisor.sales.read plus data.sensitive.read. Dates, cohort semantics, evidence and string-valued minor amounts are documented in [ADVISOR-METRICS.md](ADVISOR-METRICS.md). No AI policy decision is executed.

## Bonus policies and review cycle

See [ADVISOR-BONUS.md](ADVISOR-BONUS.md) for the versioned policy, request, reevaluation and independent decision endpoints, sensitive salary access, explicit override permissions and stale-review tokens. The engine computes and explains company-defined rules deterministically; unconfirmed coverage always requires human review. Approval records a decision only and does not disburse funds or modify payroll.

## Saved advisor reports and qualitative explanations

Read [ADVISOR-REPORTS.md](ADVISOR-REPORTS.md) for report generation/history, local-time schedules, dynamic sales/HR scope permissions and constrained report explanation endpoints. Explanations send only aggregate report facts plus the full user question to the configured provider, use existing AI quotas, and cannot execute decisions. Live provider/staging acceptance remains required.

## بوابة التكاملات

تعريف مصادر البرامج واستقبال JSON موحّد وفحصه قبل الاعتماد: [INTEGRATIONS.md](INTEGRATIONS.md). المحوّلات الأصلية لكل برنامج غير منفذة؛ source_system الخاص بالاتصال يولد تلقائياً.

## External advisory references

See [EXTERNAL-REFERENCES.md](EXTERNAL-REFERENCES.md). Authenticated active-tenant routes:

| Method | Path under /api | Permission | Body / result |
|---|---|---|---|
| GET | knowledge/external-references | ai.knowledge.read | Curated references, activity, recommended flag and tenant-scoped source state |
| PATCH | knowledge/business-activity | ai.knowledge.manage | activity: general/pharma/technology/retail/distribution/services |
| POST | knowledge/external-references/{key}/activate | ai.knowledge.manage | acknowledge: true, review_note: 12–1000 characters; returns 202 with source/document UUID and indexing state |
| POST | knowledge/external-references/{key}/disable | ai.knowledge.manage | Empty JSON body; excludes source from future retrieval |

Writes support Idempotency-Key. Only catalog keys are accepted; no arbitrary URL fetch. Activation indexes a reviewed editorial summary, not a full third-party article. Source origin and external_metadata are returned by source reads; AI citations include origin, url and publisher. Company documents cannot be added to an external-reference source. Provider configuration and ai worker remain required for indexing. No automated policy adoption or claim of Iraqi legal compliance.
