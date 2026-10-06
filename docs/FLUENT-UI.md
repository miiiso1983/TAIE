# Teami interface — Microsoft Fluent UI

The workspace uses actual Microsoft Fluent UI React v9 components (`@fluentui/react-components`) and Fluent system icons (`@fluentui/react-icons`). It is rendered by React through Laravel Vite. The Microsoft repository is https://github.com/microsoft/fluentui and the component documentation is https://react.fluentui.dev/.

## Design

- Arabic and RTL through the document, FluentProvider, forms, navigation, tables and dialogs.
- Custom editorial light/dark themes based on Fluent tokens; light is the default and the selected mode is retained locally.
- Fluent buttons, cards, fields, inputs, selects, tables, tabs, badges, avatars, menus, dialogs, drawer, message bars, spinners and toasts.
- A horizontal header on desktop and a drawer on compact screens. Tables scroll inside their containers; forms use a single column on narrow screens.
- Keyboard-accessible labels, focus-managed dialogs, a skip link and reduced-motion support.
- Dashboard counts come from tenant API metadata; no decorative chart or simulated trend is shown.

## Source files

- `resources/views/welcome.blade.php`: Laravel/Vite entry shell.
- `resources/js/app.jsx`: React entry.
- `resources/js/teami/App.jsx`: login, session, navigation, theme and workspace layout.
- `resources/js/teami/HR.jsx`: employees, employee details, leave requests, types and balances.
- `resources/js/teami/Pages.jsx`: dashboard, customers, opportunities, payroll reads, knowledge assistant and billing reads.
- `resources/js/teami/components.jsx`: shared Fluent components and data-loading/form helpers.
- `resources/js/teami/api.js`: authenticated API calls and idempotency keys.
- `resources/css/app.css`: responsive editorial layout using Fluent theme tokens.

The previous inline Blade UI and `public/js/teami-hr.js` were replaced. Server routes, permissions, tenant isolation, audit/outbox behavior, HR services and existing workflow boundaries remain intact. Changes to employment status do not disable user access. Leave approval still requires an active employee linked to the reviewer.

## Build and run

Node 20.19.x (or a later 20.x release), or Node 22.12+, and PHP 8.4.1+ are required for the supplied locks.

```sh
npm ci
npm run build
php artisan optimize:clear
php artisan serve
```

The deliverable includes built files in `public/build`, so the initial page can run without starting a separate Vite server. Rebuild after changing React or CSS source. For development, run `npm run dev` and `php artisan serve` in separate terminals. Remove a stale `public/hot` file if a previous Vite development server was stopped without cleanup.

## Verification

```sh
php artisan test
npm run build
npx playwright install chromium
```

With a local Laravel server running at http://127.0.0.1:8000:

```sh
npm run test:ui
```

Or let the browser test runner start the Laravel server:

```sh
TEAMI_UI_START_SERVER=1 npm run test:ui
```

The browser suite mocks API responses with fictitious records while exercising the real built frontend and Laravel shell. It does not require production accounts or API keys and does not contact an AI provider. `TEAMI_UI_URL`, `TEAMI_PHP_BINARY`, and `TEAMI_CHROMIUM_PATH` support alternate local test environments.

Verification results are recorded in `docs/VERIFICATION-FLUENT-UI.md`. Screenshots in `docs/previews/` show fictitious test records.
