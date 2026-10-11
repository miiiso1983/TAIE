# TAIE visual identity redesign

Implemented from the supplied six-page Visual Identity guide on 2026-10-11.

## Identity and interface

- Indigo #3700B3, violet #6200EE, night #14003A, sky #54BBFC, AI spark #45C98D and mist #E6E6E6.
- Self-hosted IBM Plex Sans Arabic, IBM Plex Sans and Syne fonts, with their OFL licenses in public/fonts. Sources: Google Fonts repositories ofl/ibmplexsansarabic, ofl/ibmplexsans and ofl/syne.
- Modular vector brand mark reconstructed from the guide, with light/dark wordmark treatment and SVG app icon. The vector is a reconstruction, not an extracted original artwork file.
- Login, dashboard hero, navigation, workspace strip, cards, metrics, tables, forms, dialogs, searchable lists and AI presentation use the shared identity layer.
- Responsive spacing and typography, RTL support, light/dark themes; decorative graphics use rectangular modules rather than the previous photographic hero.
- Existing business logic, permissions and data flows retained. No database migration, seed or production data changes.

## Validation

- Laravel: 158 tests passed, 1672 assertions, using isolated in-memory SQLite.
- Vite production build: passed. Public font URL warnings resolve to existing local files; existing Fluent chunk-size warning remains.
- Pint on changed files: passed. Full Pint reports pre-existing formatting in EnforceAiQuota.php, CompanyRuleService.php, ManualBillingController.php and LaunchReadinessTest.php; these files were not changed by the redesign.
- git diff --check: passed.
- Browser suite: 24 scenarios discovered; not executed successfully because the local automated Chromium process fails to launch. Browser visual acceptance remains pending; build/test success does not establish pixel-level acceptance.
- A local preview of the actual login bundle is provided in this chat's outputs. API requests are blocked in that preview; it contains no production data.
