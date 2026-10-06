#!/usr/bin/env bash
set -euo pipefail
# Run from the NEW release directory after dependencies, shared storage and .env are ready.
# Require a verified backup before schema changes. This script does not switch the live symlink.
: "${TEAMI_BACKUP_VERIFIED:?Set TEAMI_BACKUP_VERIFIED=yes after verifying a current backup}"
[[ "$TEAMI_BACKUP_VERIFIED" == yes ]] || exit 1
teami_php="${TEAMI_PHP_BINARY:-php}"
[[ -f public/build/manifest.json && ! -f public/hot ]] || { echo 'Build production assets first'; exit 1; }
"$teami_php" artisan migrate --force
"$teami_php" artisan db:seed --class=DatabaseSeeder --force
"$teami_php" artisan teami:permissions:sync-owners
"$teami_php" artisan optimize
"$teami_php" artisan teami:launch:check
# After switching current to this release, restart workers and verify runtime, mail and AI.
