# Excel and first sales metrics — 2026-10-05

Implemented: XLSX (OpenSpout 4.32.0), sheet selection, explicit column mapping with optional missing fields, protected header inspection, and initial deterministic sales/collection metrics with invoice evidence. No AI-driven recommendations or approval decisions.

- **59 backend tests / 422 assertions passed**, SQLite development test database.
- Advisor import/metrics subset: 14 tests / 100 assertions. New cases cover real XLSX sheet selection, Arabic headers, optional mapping, CSV mapping collisions, Date-style conversion and exact money/deduplication, formula refusal, XML entity and external relationship refusal, archive traversal and compression bounds, invoice-cohort/as-of calculations, separate currencies, tenant/permission/date guards and evidence.
- **13 browser scenarios passed**. New UI coverage checks XLSX inspection/mapping and read-only metric evidence. After isolating monetary values from RTL text, the two affected scenarios were rerun successfully. Browser responses are fixture responses; real file parsing and database calculations are covered by backend tests.
- Production frontend build, Composer validation/audit and Laravel Pint passed. Composer runtime audit returned no known vulnerability advisories. No existing locked package was updated; OpenSpout was added and bcmath made explicit.
- New screens visually inspected. Financial values returned as integer-unit strings and displayed with BigInt; no cross-currency sum.
- New permission advisor.sales.read, plus data.sensitive.read, protects metrics. Existing import authorization remains enforced on inspect/preview/commit. Run db:seed and teami:permissions:sync-owners during upgrade.
- No new migration in this step; advisor import migration from the preceding package remains required. Preserve the real .env, encryption/audit keys, database and private storage.

Staging/MySQL/Redis concurrency and production mail/AI/backup restoration remain unverified. Read ADVISOR-IMPORTS.md, ADVISOR-METRICS.md and ADVISOR-ROADMAP.md for limits and later stages. Financial coverage is explicitly unconfirmed; these indicators cannot decide a bonus without returns, targets and company policy.
