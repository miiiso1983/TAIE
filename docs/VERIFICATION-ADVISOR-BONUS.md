# First bonus policy/review cycle — 2026-10-05

Implemented returns, exact-period employee/currency targets, encrypted annual compensation imports with overlap refusal, net/credit-aware sales metrics, immutable policy versions, deterministic Arabic reasons, sensitive encrypted review snapshots and independent documented decisions. Coverage remains explicitly unconfirmed; every request needs human review. No model call, payroll update or disbursement is performed.

- Backend: **70 tests / 531 assertions passed** on SQLite, including 11 new bonus/input tests (109 new assertions relative to the prior release).
- New coverage includes return/date/amount guards and as-of cutoffs, targets without invoices, overlap refusal, salary import permissions and encryption, sales/collection/cap explanations, precise IQD cap rounding, salary currency mismatch, tenant boundaries, version identity, subscription write gating, duplicate request/idempotent replay, independent approval, enforced override permission, advisory manual reasons, rejection, stale financial/salary data and stale client review tokens.
- Browser fixture scenarios cover policy creation, request submission, explanatory evidence before decision, correct decision review tokens/idempotency key, hidden menus without sensitive/salary grants and updated metric tables. **All 15 scenarios passed in the final full browser run**. The affected bonus/analytics/mobile scenarios were also verified after layout corrections. Backend tests use real database/import calculations; browser tests mock API responses.
- Production frontend build and Composer strict validation passed. Laravel Pint passed for changed PHP files. Locked dependencies were not changed in this increment.
- Tenant-before-employee lock order is shared by advisor writes and manual compensation/employee updates, including calls without idempotency headers. MySQL/Redis concurrency behavior still requires staging tests; SQLite results do not establish concurrent production safety.
- Upgrade adds migration 2026_10_05_000003 and five advisor permissions. Run migrate, db:seed and teami:permissions:sync-owners while keeping existing secrets/database/private storage. Production launch is not completed by this package.

Read ADVISOR-BONUS.md, ADVISOR-METRICS.md, ADVISOR-IMPORTS.md and ADVISOR-ROADMAP.md for exact semantics and remaining phases. Incomplete source coverage, opening balances, general credit adjustments/refunds, attendance, model-based business narratives and periodic advisor reports remain unimplemented.

Visual QA also identified and fixed the inherited Fluent provider canvas on toast portals, which could cover the viewport after saves. Dense financial tables now have explicit scrollable widths and explanatory reasons wrap. Source-scope canvas isolation keeps portal surfaces transparent while preserving Fluent theme variables.

The final Advisor-only backend pass after a lookup optimization completed 25 tests / 209 assertions; the full 70-test run also passed after aligning employee write locks. Primary header actions retain white text on the wine fill.
