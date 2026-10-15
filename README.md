# VAULT24 — full stack (fictional prototype)

```
backend/    PHP 8.2+ API (SQLite)       security-first, 48 tests      -> http://localhost:8000
frontend/   customer-app (React/Vite)   customers                     -> http://localhost:5173
            admin-app    (React/Vite)   staff & admins                -> http://localhost:5174
integration/ run-e2e.sh                 runs the REAL frontend api.js against the REAL backend (41 checks)
dev.sh                                  one command to run everything
```

## Run it
Requirements: PHP ≥ 8.2 (`pdo_sqlite`, `mbstring`), Node ≥ 18.
```bash
./dev.sh
```
Then open **http://localhost:5173** (customer) and **http://localhost:5174** (admin).
Manual alternative: see `backend/README.md` and `frontend/README.md`.

### Fictional demo accounts (created by `backend/bin/seed.php`)
| App | Email | Password | Notes |
|---|---|---|---|
| customer | alice@vault24.test | `Alice-Demo-Pass-24!` | 5,000.00 balance — use to send money |
| customer | bob@vault24.test | `Bob-Demo-Pass-2024!` | 2,500.00 |
| admin | admin@vault24.test | `Admin-Demo-Pass-24!` | full access: balances, audit metadata, staff accounts |
| admin | staff@vault24.test | `Staff-Demo-Pass-24!` | limited: no balances, no audit metadata |

New customers registered in the UI start with **0.00** (balances can only move through transfers) — send them money from Alice.
The old mock passwords (`Password123!`) only apply when an app runs with `VITE_USE_MOCK=true`.

## How the two halves talk
| Concern | Design |
|---|---|
| Transport | Browser → Vite dev proxy (`/api`) → PHP `:8000`. Same-origin, so cookies just work. |
| Session | `HttpOnly; SameSite=Strict` cookie. The token is never in a JSON body or JS. Customer app: `v24_session`; admin app: `v24_admin_session`. |
| CSRF | Cookie-authenticated writes require `X-Requested-With: XMLHttpRequest` + a trusted `Origin` (set in `backend/.env` → `CORS_ALLOWED_ORIGINS`). |
| Contract | `frontend/*/src/services/api.js` unwraps `{success,data,request_id}` and maps fields/error codes to what the pages expect. |
| Money | Integer minor units end to end; the UI shows balances the server returns and never computes them. |
| Idempotency | The transfer screen sends a fresh `Idempotency-Key` per reviewed transfer; retries/double-clicks cannot double-spend. |
| Roles | Enforced by the server on every route (UI guards are cosmetic). Staff get `balanceMinor: null` and no audit metadata from the API itself. |

### What I changed to make them compatible (and why)
**Backend (additive):** 10-digit server-assigned account numbers (were `V24-…`), transfer `note`, history with counterparty name/status,
`source_balance_minor` in the transfer response, cookie sessions + CSRF + separate admin portal login, and the admin endpoints the admin app
needed (`customers`, `security-events`, `staff` list/create; audit log readable by staff without metadata).
**Frontend:** `api.js` rewritten for the real API; Express `:4000` proxy → PHP `:8000`; **registration no longer asks for an account number**
(the server assigns it — a client-chosen account number is an attack surface) and shows it after sign-up; password minimum 10 → **12**
(server policy); admin "create staff" asks for the admin's own password (re-authentication for a high-risk action).

## Verify
```bash
cd backend && php tests/run.php          # 48 backend tests
./integration/run-e2e.sh                 # frontend api.js <-> live backend, 41 checks (needs npm install done once)
```

## Known limitations
- The apps show amounts with a "K" prefix; the backend's simulated currency code is `VLT`. Cosmetic only.
- History shows the latest 50 transactions; admin lists load the first 100 rows (backend supports paging; UI has none yet).
- Admin overview "Audit events" counts the loaded page (max 100), not the table total.
- Lock/unlock of users exists in the API (`/admin/users/{id}/lock`) but the admin UI has no button yet.
- Production needs HTTPS (`COOKIE_SECURE=true`, `ENABLE_HSTS=true`) and the apps served from the same site as the API (or listed in `CORS_ALLOWED_ORIGINS`).
# vault24-fullstack
