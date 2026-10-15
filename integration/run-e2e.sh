#!/usr/bin/env bash
# Boots a throw-away backend (temp DB, seeded demo users) and runs the frontend<->backend contract test.
#   ./integration/run-e2e.sh      (needs php + node, and `npm install` done in frontend/customer-app and admin-app)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; PORT="${PORT:-8000}"
export APP_ENV=development DB_PATH="$TMP/e2e.sqlite" LOG_PATH="$TMP/e2e.log" CORS_ALLOWED_ORIGINS="http://localhost:5173,http://localhost:5174"

if curl -fs "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then
  echo "Port $PORT is already serving something - stop it first (or run with PORT=8010)." >&2; exit 1
fi
cd "$ROOT/backend"
php bin/migrate.php >/dev/null && php bin/seed.php >/dev/null
# Single PHP process: multi-worker mode spawns children that can outlive the parent. Tests are sequential anyway.
php -S 127.0.0.1:$PORT -t public public/index.php >"$TMP/server.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true; wait $SRV 2>/dev/null || true; rm -rf "$TMP"' EXIT
for _ in $(seq 1 50); do curl -fs "http://127.0.0.1:$PORT/health" >/dev/null && break; sleep 0.1; done
API_ORIGIN="http://127.0.0.1:$PORT" node "$ROOT/integration/e2e.mjs"
