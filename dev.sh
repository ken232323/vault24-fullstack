#!/usr/bin/env bash
# One command: migrate + seed the backend, then start the API (:8000), customer app (:5173) and admin app (:5174).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
[ -f "$ROOT/backend/.env" ] || cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
for app in customer-app admin-app; do [ -d "$ROOT/frontend/$app/node_modules" ] || (cd "$ROOT/frontend/$app" && npm install --no-audit --no-fund); done
( cd "$ROOT/backend" && php bin/migrate.php && php bin/seed.php )
pids=()
trap 'kill "${pids[@]}" 2>/dev/null || true' EXIT INT TERM
( cd "$ROOT/backend" && exec php -S localhost:8000 -t public public/index.php ) & pids+=($!)
( cd "$ROOT/frontend/customer-app" && exec npm run dev ) & pids+=($!)
( cd "$ROOT/frontend/admin-app" && exec npm run dev ) & pids+=($!)
echo; echo "API http://localhost:8000   Customer http://localhost:5173   Admin http://localhost:5174   (Ctrl+C to stop)"
wait
