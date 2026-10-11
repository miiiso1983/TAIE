# Cloudways deployment — 2026-10-11

Repository: miiiso1983/TAIE, branch main. Target app: rcxvdgmqjk, https://phpstack-1605362-6721425.cloudwaysapps.com/.

Code resides in /home/master/applications/rcxvdgmqjk/private_html/teami/releases/fb617bd. The directory name reflects the initial checkout; subsequent fast-forward fixes update the recorded Git HEAD. public_html/index.php dispatches the private release's public/index.php; only built frontend assets, fonts, brand graphics and .htaccess are copied into the public directory. Future releases must update both the private checkout and public assets together.

PHP 8.4 is active for web requests; PHP CLI commands explicitly use /usr/bin/php8.4. Node 20.5.1 on the host is below the frontend build requirement, so production assets were built locally from the same source and uploaded. Composer production dependencies were installed on the server and all platform requirements passed.

MySQL connection was verified and initially contained zero tables. Initial migration failed because ten generated index/constraint names exceeded MySQL's 64-character limit. Explicit shorter names preserve the same columns and constraints. A private SQL backup was captured before removing only the four empty partial tables from the failed migration. After correction, all 34 migrations and permission seeding completed successfully. No pre-existing business records were deleted.

Redis is unavailable in this app's current configuration. Cache, sessions and queues use durable database drivers. Existing crontab entries were preserved; app-specific scheduler and queue worker entries were added. The worker is protected by flock, consumes critical,ai,analytics,default and exits after 3600 seconds; cron restarts it on the next minute. It is not a Supervisor-managed process. Timeout is 120 seconds; database queue retry_after is 180 seconds.

Private backups include the previous default public page, the initial empty schema and the prior crontab. Secrets remain in server-side .env; no password or encryption key was committed or copied into this report.

## Verified

- Local Laravel suite: 159 passing tests, 1674 assertions after launch fixes.
- Local Vite production build, changed-file Pint and diff whitespace check passed.
- Real HTTPS root, /up, built JS/CSS, Arabic font and SVG icon return 200.
- .env and .git/config requests return 403; composer.json returns 404.
- Authenticated API boundaries return 401 to unauthenticated JSON requests. Requests without Accept are also covered by a new regression test to prevent login-route redirect errors.
- Server launch check with --runtime passes database, cache, schema, permission catalog, scheduler and worker heartbeats.

## CI scope and remaining acceptance

The original GitHub CI exposed two SQLite-specific tests being executed against MySQL and an incomplete economic-comparison browser fixture. SQLite cold restore and transactional legacy-schema emulation remain fully tested in a dedicated SQLite CI job; the MySQL job explicitly skips only those two driver-specific scenarios. The browser fixture now matches the comparison response shape. Check the latest GitHub workflow run for final results.

No real owner account, subscription, SMTP delivery or AI provider call has been provisioned/tested by this deployment. Create the initial company and owner through the interactive teami:tenant:create command; password input stays on the server. Do not disable billing enforcement globally to bypass subscription setup. Real MySQL backup restoration and production load/concurrency acceptance remain separate from schema/readiness checks.
