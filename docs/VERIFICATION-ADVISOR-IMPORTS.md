# Advisor import verification — 2026-10-05

Implemented scope: first CSV cycle of roadmap phase 1, not the complete AI advisor.

- Laravel suite: **53 tests / 379 assertions passed**, including 8 new import tests (57 assertions).
- Covered: encrypted preview, no business writes before explicit commit, repeat-file and repeat-commit protection, invalid batch rejection, invoice/receipt links and exact USD/IQD minor units, cumulative overpayment rejection, foreign tenant references, expired preview, permission changes, stale data, UTF-8 BOM, header/empty/1000-row bounds and expired-subscription gating.
- Playwright: 10 existing UI scenarios passed; the new CSV scenario passed after correcting the native file control's accessible label. The new test checks actual multipart upload, preview before commit, explicit approval and idempotency header. API responses are intercepted fixture responses; backend persistence/security is covered separately above.
- Production build, PHP formatter, API JavaScript syntax and schedule inventory passed. New import screen visually inspected.
- No AI provider calls, SMTP delivery or public deployment were performed. MySQL concurrency is a staging/CI acceptance gate; local test DB is SQLite. XLSX/OCR, financial-analysis calculations, policy engine and advisor decisions remain later roadmap work.

Upgrade needs migration 2026_10_05_000002, db:seed and teami:permissions:sync-owners. Preserve .env, APP_KEY, AUDIT_HMAC_KEY and private storage. The delivered archive excludes runtime secrets, databases, dependencies and private uploads.
