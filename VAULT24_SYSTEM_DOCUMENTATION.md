# VAULT24 SYSTEM DOCUMENTATION

## Table of Contents

1. [Title Page](#title-page)
2. [Introduction](#introduction)
3. [System Overview](#system-overview)
4. [Technologies and Dependencies](#technologies-and-dependencies)
5. [System Architecture](#system-architecture)
6. [Frontend Documentation](#frontend-documentation)
7. [Backend Documentation](#backend-documentation)
8. [Database Design and Integration](#database-design-and-integration)
9. [End-to-End Data Flow](#end-to-end-data-flow)
10. [Authentication and Authorization](#authentication-and-authorization)
11. [System-Wide Security Analysis](#system-wide-security-analysis)
12. [Frontend and Backend Integration](#frontend-and-backend-integration)
13. [Installation and Configuration](#installation-and-configuration)
14. [Testing and Troubleshooting](#testing-and-troubleshooting)
15. [Limitations and Recommended Improvements](#limitations-and-recommended-improvements)
16. [Glossary of Technical Terms](#glossary-of-technical-terms)
17. [Conclusion](#conclusion)
18. [Appendix](#appendix)

## 1. Title Page

**Project:** VAULT24 — Secure Backend (fictional prototype)
**Type:** Modular monolith web application (PHP backend + React/Vite frontend)
**Description:** A security-first fictional digital banking prototype with defense-in-depth controls implemented in code.
**Generated on:** 2026-10-15
**Repository root:** `/home/ken/Work/vault24-fullstack`

> **Fictional only.** All users, balances and transfers are simulated. Not production banking software.

## 2. Introduction

VAULT24 is a small, self-contained demonstration of a "secure backend" design for a fictional banking system. It emphasizes concrete, verifiable security controls: authentication, authorization, input validation, rate limiting, login throttling with progressive lockout, idempotent transfers, atomic writes, redacted audit trail, append-only records, and defense-in-depth boundaries.

### Core Components
- **Backend (PHP)**: Plain PHP 8.2+ (no Composer runtime dependencies). Uses PSR-4 autoloading (`Vault24\\...`) under `backend/src`. Custom `Kernel`, `Request`, `Response`, `Validator`, services, policies, and controllers. Exposes JSON API under `/api/v1`. Entry point: `backend/public/index.php`.
- **Frontends (React + Vite)**: `frontend/customer-app` (customers) and `frontend/admin-app` (staff/admin). Both use Mantine UI, React Router, React Query, and a shared API client (`src/services/api.js`). In dev, Vite proxies `/api` to the backend (port 8000); session cookies (`HttpOnly; SameSite=Strict`) are used for browser auth.
- **Database (SQLite)**: `backend/storage/vault24.sqlite`. Schema defined in `backend/database/schema.sql`; created/migrated by `backend/bin/migrate.php`. Contains triggers to prevent mutation/deletion of immutable records.
- **Tests**: 48 feature tests in `backend/tests/Feature/*` (PHP harness). Integration end-to-end contract test `integration/e2e.mjs` runs real frontend API modules against a real backend.

## 3. System Overview

### What It Does
- Customer registration (role/customer, 10-digit account number assigned server-side, initial balance 0), login/logout, session management.
- View own account and simulated balance; list own transaction history (ledger entries filtered to caller's account).
- Create transfers between different customer accounts with required `Idempotency-Key` (replay-safe). Source account is always derived from authenticated caller (never from request). Atomicity via `BEGIN IMMEDIATE` transaction.
- Profile update (only `name`).
- Backoffice: customers list (staff see no balances; admin sees balances), audit events (staff see no metadata; admin sees full), security events (server-computed severity), staff list, create staff/admin (admin-only with re-authentication), lock/unlock users (admin-only with re-authentication; locking revokes all sessions).
- Append-only audit trail with event-type allowlists and redaction; DB triggers prevent updates/deletes on `transfers`, `ledger_entries`, `audit_events`.

### Technologies
- **Backend**: PHP 8.2+, `pdo_sqlite`, `mbstring`. No runtime Composer packages. Namespaces `Vault24\\...` (autoload via `spl_autoload_register`).
- **Frontend**: React 18, Vite 5, React Router 6, @tanstack/react-query 5, Mantine 7, zod, vite proxy for API.
- **Database**: SQLite 3 (WAL journal mode, `foreign_keys=ON`, `busy_timeout=8000`).

## 4. Technologies and Dependencies

### Backend
- PHP 8.2+ (tested with 8.5.10), extensions: `ext-pdo_sqlite`, `ext-mbstring`.
- No third-party runtime packages (see `backend/composer.json`, `composer.lock` empty packages).
- Autoload: custom PSR-4 style via `spl_autoload_register` (prefix `Vault24\\` → `src/`).

### Frontend (both apps)
- React ^18.3.1, React DOM ^18.3.1, React Router DOM ^6.28.0
- @tanstack/react-query ^5.62.0
- @mantine/core ^7.17.0, @mantine/form ^7.17.0, @mantine/hooks ^7.17.0, @mantine/notifications ^7.17.0, @tabler/icons-react ^3.31.0
- mantine-form-zod-resolver ^1.1.0, zod ^3.23.8
- Vite ^5.4.11, @vitejs/plugin-react ^4.3.4, PostCSS
- node_modules present in both frontend apps.

### Infrastructure
- SQLite database file at `backend/storage/vault24.sqlite`
- PHP built-in dev server: `php -S localhost:8000 -t public public/index.php`

## 5. System Architecture

### High-Level Flow
User → Vite Frontend (customer:5173/admin:5174) → (Vite proxy `/api` → Backend :8000) → PHP Front Controller (`public/index.php`) → Kernel → Request/Validation/Services/Policies → SQLite DB → JSON Response → Frontend (React Query).

### Backend Request Pipeline (Kernel)
1. Request from globals (`Request::fromGlobals`) — parses headers, body (size-limited), query, IP with trusted-proxy handling.
2. Kernel wraps request in try/catch → assigns request_id, dispatches.
3. Size limit check (`max_body_bytes`), OPTIONS short-circuit (204).
4. Route match: method + regex (supports `{param}`), collect allowed methods on miss.
5. Authentication: if not public, extract token from `Authorization: Bearer` or from appropriate cookie (`v24_session` for customer/admin paths by path-based logic in `SessionCookie::nameFor`), assert same-site/browser checks for cookie-authenticated non-GET, resolve via `AuthService::authenticate` (checks revoked, idle, absolute expiry, user status; updates last_seen_at).
6. Authorization: role check against allowed roles for matched route.
7. Throttling: if route has throttle config, check `RateLimiter` per key (`user:id` or `ip:ip`) and throw 429 with Retry-After if exceeded.
8. Handler: call controller with `(Request, Principal?, params)`.
9. Response: finalize with security headers, CORS (allowlist only, sets `Access-Control-Allow-Credentials` only with explicit origin), request_id in payload.

## 6. Frontend Documentation

### Customer App (`frontend/customer-app`)
Structure: `src/main.jsx`, `App.jsx`, `context/AuthContext.jsx`, `services/api.js`, `services/mock.js`, `layouts/*`, `pages/*`, `components/*`, `schemas/*`, `utils/money.js`, `theme.js`. Vite config proxies `/api` to `http://localhost:8000`, port 5173.

- **main.jsx**: wraps app with `BrowserRouter`, `MantineProvider`, `Notifications`, `QueryClientProvider`, and `AuthProvider`. React Query defaults: `retry:false`, `refetchOnWindowFocus:false`.
- **AuthContext**: identity from server via `GET /me` (never localStorage); treats 401 as logged out. Exposes `user`, `loading`, `login`, `register`, `logout`. On logout clears query cache.
- **services/api.js (real)**: base from `VITE_API_BASE` (default `/api/v1`), `credentials: 'include'`, always sends `X-Requested-With: XMLHttpRequest` and `Content-Type: application/json` for non-GET. Unwraps `{success,data,request_id}` envelope; maps backend error codes to UI-friendly codes (e.g. `validation_failed` details → `RECIPIENT_NOT_FOUND`, `EMAIL_TAKEN`, `WEAK_PASSWORD`; `invalid_credentials` → `INVALID_CREDENTIALS`; `too_many_attempts` → `TOO_MANY_ATTEMPTS` with Retry-After-based message; `insufficient_funds` → `INSUFFICIENT_FUNDS`; auth failures → `UNAUTHENTICATED`). For browser clients, server returns cookie-only token; API client never reads/stores tokens.
- **Routes**: Guest routes (`/login`, `/register`) wrapped in `GuestRoute`; protected routes under `CustomerLayout` (`/dashboard`, `/transfers/new`, `/transactions`, `/profile`). Unknown paths redirect to `/dashboard`.
- **Pages**: Dashboard (account summary, recent activity), Transfer (create transfer with idempotency key generation), Transactions (ledger list), Profile (update name), Login, Register.

### Admin App (`frontend/admin-app`)
Similar structure; uses separate cookie namespace conceptually (`v24_admin_session` set by backend on admin login) and admin API endpoints. Guards: `ProtectedRoute` requires `staff` or `admin`; `RequireRole` restricts staff-management to `admin`. API maps admin-specific endpoints (`/admin/*`).


## 7. Backend Documentation

### Entry & Autoload
- `backend/public/index.php`: sets `display_errors=0`, enables `log_errors`, loads `src/autoload.php`, instantiates `Kernel(Config::fromEnv($root))`, dispatches `Request::fromGlobals()`, sends response.
- `src/autoload.php`: PSR-4 autoload for `Vault24\\` prefix (maps to `src/` subdirs).

### Kernel (`src/Http/Kernel.php`)
- Registers services on construct: `Database`, `Logger`, `AuditService`, `PasswordHasher`, `AuthService` (with `LoginThrottle`), `TransferService`, `RateLimiter`. 
- Registers routes with access (public/array of roles) and optional throttle config. Pattern `{param}` converted to regex `(?P<param>[^/]+)`.
- Dispatch flow as described above; 405 includes Allow header; 404 for no match. For unauthorized access to protected routes, throws 401 with `WWW-Authenticate: Bearer` when no token; 403 with audit trail when role insufficient. Cookie-authenticated non-GET requests pass `assertSameSiteRequest` (requires browser client via `X-Requested-With` and valid Origin against allowlist or matching Host).
- Finalize adds security headers: `X-Request-Id`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Cache-Control: no-store`, `Pragma: no-cache`, `Permissions-Policy`. Adds `Strict-Transport-Security` if `hsts=true`. Adds CORS headers only when Origin is in allowlist (explicit, never `*`), with `Access-Control-Allow-Credentials: true`.

### Request (`src/Http/Request.php`)
- Parses headers (prefixed HTTP_ + CONTENT_*), raw body (clamped to `max_body_bytes+1`), path (trailing slashes trimmed), query from `$_GET`. 
- Client IP: only trusts `X-Forwarded-For` chain when direct peer (`REMOTE_ADDR`) is in `trusted_proxies`; iterates from right-to-left to find first untrusted IP.
- `cookie(name)`: parses Cookie header, validates token-like format (`^[A-Za-z0-9_\-]{20,128}$`).
- `isBrowserClient()`: returns true if `X-Requested-With === 'XMLHttpRequest'`.
- `bearer()`: extracts Bearer token if matches format.
- `json()`: requires `Content-Type: application/json`, decodes with JSON_THROW_ON_ERROR, enforces object (not array/list), caches result.

### Response (`src/Http/Response.php`)
- Envelope shape: `{'success': true, 'data': ...}` or `{'success': false, 'error': {...}}`. `request_id` added in finalize.
- `send()`: clears `X-Powered-By`, sets status, sends headers, echoes JSON (unescaped slashes/unicode).

## 8. Database Design and Integration

Schema: `backend/database/schema.sql`. SQLite with `PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 8000;`.

Tables (verified):
- **users**: id (PK), public_id (unique, e.g. `usr_*`), name, email (unique, normalized), password_hash, role (`customer|staff|admin`), status (`active|locked`), created_at (UTC Z).
- **accounts**: id (PK), user_id (UNIQUE FK → users), account_ref (UNIQUE, 10-digit), currency (`VLT`), balance_minor (>=0 INT), status (`active|frozen`), created_at. One account per customer (others have no accounts created in normal flows).
- **sessions**: id (PK), user_id (FK → users), token_hash (sha256 of token, unique; raw token never stored), created_at (unix), last_seen_at (unix), expires_at (unix), revoked_at (unix|null). Index `idx_sessions_user`.
- **transfers**: id (PK), reference (UNIQUE, e.g. `TRF-...`), source_account_id (FK), destination_account_id (FK), amount_minor (>0 INT), currency, status (`completed` only in this design), note (<=60), created_at. CHECK `source_account_id <> destination_account_id`. Immutable via triggers.
- **ledger_entries**: id (PK), transfer_id (FK), account_id (FK), direction (`debit|credit`), amount_minor (>0 INT), created_at. UNIQUE (transfer_id, direction). Index on (account_id,id). Immutable via triggers.
- **idempotency_keys**: key, user_id (FK), request_hash (sha256 of canonical payload), transfer_id (FK), created_at. PK is (user_id, key). Prevents replays and catches payload changes.
- **audit_events**: id (PK), actor_user_id (FK nullable), event_type, target_type, target_id, outcome (`success|failure|denied`), request_id, meta (JSON, allowlisted+redacted), created_at. Index on (event_type,id). Immutable via triggers.
- **login_attempts**: id, bucket (`acct:sha256(email)` or `ip:<ip>`), created_at (unix). Index (bucket, created_at) for cleanup/throttling.
- **rate_limits**: bucket, window_start (unix aligned to window), hits. PK (bucket, window_start).

Triggers (defense-in-depth, app-level also enforces):
- `ledger_no_update/delete` → ABORT
- `transfers_no_update/delete` → ABORT
- `audit_no_update/delete` → ABORT

Migration: `Database::migrate()` runs schema; also adds `note` column to `transfers` if missing (backward-compatible). Idempotent.

## 9. End-to-End Data Flow

### Registration (Customer)
1. User submits form on `/register` (customer app).
2. Frontend validates (Zod schema); sends `POST /api/v1/auth/register` with body `{name,email,password}`, `X-Requested-With`, credentials, JSON.
3. Kernel: public route, no auth. Validator enforces keys, name length, email format, password policy (min 12, byte limit per hasher, against CommonPasswords + entropy heuristics). Reject unknown fields (role/status/balance/ids) with 422.
4. `AuthService::createUser`: compute hash before transaction. Transaction: check duplicate email → 422 if exists. Generate `public_id` (`usr_*`), insert user (role fixed by code parameter; registration sets `customer`). If customer, generate unique 10-digit `account_ref` (starts with `10`, 8 more digits), create account with balance 0.
5. Audit `register.success`. Return 201 with user (public fields) and `account_ref`. (No token/cookie on registration.)
6. Frontend receives and can redirect to login.

### Login (Customer)
1. `POST /api/v1/auth/login` with credentials. 
2. `AuthService::login`: `LoginThrottle::assertAllowed` (per account/IP) → may 429. Always verify password (using dummy hash if user missing) to prevent timing enumeration. Check user exists, password ok, status active, roles allowed. On failure: record login_attempts, audit `login.failure` (generic reason), return 401 generic message.
3. On success: clear account failures, rehash if needed, generate fresh random token, store token_hash (sha256), set session expiry (absolute), audit `login.success`.
4. If browser client (X-Requested-With), return JSON data (user/expires_at) with `Set-Cookie` for `v24_session` = token, `HttpOnly; SameSite=Strict; Path=/; Secure` only if `cookie_secure` true. If not browser, return token in JSON as Bearer.
5. Frontend stores user in React Query cache; redirects to `/dashboard`.

### Transfer Create (Customer)
1. User submits transfer form: to account number (10 digits), amount_minor (int), optional note (<=60). Frontend generates `Idempotency-Key` (UUID) per attempt.
2. `POST /api/v1/transfers` with header `Idempotency-Key: ...`, body includes `destination_account_ref`, `amount_minor`, optional `currency`, optional `note`. Cookie-authenticated (browser) so same-site checks apply.
3. Kernel: requires role `customer`, throttled by user (`transfer_rate_limit` per minute). Authenticates via cookie; asserts browser client and Origin allowlist/trust.
4. `TransferController::create`: validate Idempotency-Key format (8-64 chars). Validator: only allowed keys, dest 10 digits, amount between 1 and `max_transfer_minor`, currency must match if present, note length. Fail on validation errors.
5. `TransferService::create` inside transaction `BEGIN IMMEDIATE` (write lock held):
- Check idempotency key for this user: if exists with different request_hash → 409 (`idempotency_key_conflict`). If same hash → return prior transfer view as replayed (HTTP 200).
- Load source account by `user_id` (from Principal) — never from request. Ensure active.
- Load destination by account_ref; ensure exists, active, different from source. Reject if invalid (422 with dest field detail mapped to RECIPIENT_NOT_FOUND by frontend).
- Check funds: source.balance_minor >= amount. Check destination + amount <= max_balance_minor.
- Debit: `UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ? AND balance_minor >= ?` — rowCount must be 1 (guards race). Optionally call test fault hook. Credit destination. 
- Create transfer with unique `TRF-` reference, insert ledger_entries (debit + credit), insert idempotency_keys row.
- Invariant check: exactly 2 ledger entries, debit sum == amount, credit sum == amount. If violated, throw LogicException (logged, generic 500).
- Return transfer view (no internal ids).
6. On success and not replayed, audit `transfer.created` with reference and parties/amount/currency. On HttpException, audit `transfer.failed` with reason; on other throwables audit and let Kernel return generic 500.
7. Controller returns 201 (created) or 200 (replayed) with transfer data plus `replayed` flag and `source_balance_minor` (fresh read from committed state). Frontend updates balances/transactions.

## 10. Authentication and Authorization

### Authentication
- **Browser clients**: authenticate via HttpOnly SameSite=Strict cookie. For customer routes, cookie name `v24_session`; for admin routes (`/api/v1/admin/*`), cookie name `v24_admin_session` (separate). Cookie is set only on login when `X-Requested-With` present; JS never sees token. Non-browser clients may authenticate via `Authorization: Bearer <token>` (returned in JSON on login when not browser).
- **Session storage**: sessions table stores `token_hash = sha256(token)`; raw token never stored. On authenticate, check: not revoked, `expires_at > now`, `last_seen_at + idle > now`, user status `active`. On success, update `last_seen_at = now`.
- **Logout**: sets `revoked_at` for current session; for browser clients clears the appropriate cookie.
- **Re-authentication**: high-risk admin actions (`lock/unlock`, `createStaff`) require `current_password` and verify against current user’s hash (403 with `reauthentication_failed` if wrong).

### Authorization
- **Route-level**: Kernel checks allowed roles array for protected routes. Public routes marked `'public'`. If authenticated but role not in allowed list → audit `authz.denied` with route and required_roles → 403 `forbidden`.
- **Object-level**: `TransferPolicy::canView` allows if staff/admin, else checks if caller is owner of source or destination account for that transfer. When denied, callers receive 404 `not_found` (to avoid revealing existence).
- **Backoffice segregation**: admin endpoints require `staff|admin` as appropriate (e.g. staff list/create staff require `admin` only). Customer sessions are different cookie; customer attempting admin login gets generic 401 `invalid_credentials` (not 403) to avoid role probing in some cases? The admin login route accepts allowed roles `['staff','admin']` and if user is customer/locked/wrong role, login fails with generic 401.

## 11. System-Wide Security Analysis

### Implemented & Verified Controls
| Control | Details | Status |
|---|---|---|
| Password storage | Argon2id preferred (or bcrypt fallback), salted via PHP password_* API; never plaintext. Rehash on login if params changed. | Implemented & verified |
| Timing-safe auth checks | Dummy hash used on unknown email; password_verify called in both cases. | Implemented & verified |
| Login throttling | Per-account and per-IP buckets in `login_attempts`; progressive lockout (30s,60s,120s...) capped at window; attempts while locked not recorded. | Implemented & verified |
| Generic auth errors | 401 `invalid_credentials` for bad email/password/wrong portal; avoids account enumeration. | Implemented & verified |
| Session management | HttpOnly SameSite=Strict cookies (Secure when configured), separate cookie names per app, token hashes only, idle + absolute expiry, revocation on logout/lock. | Implemented & verified |
| CSRF defense | Cookie-authenticated non-GET require `X-Requested-With: XMLHttpRequest` and Origin allowlist/trust check; GET/HEAD/OPTIONS exempt. | Implemented & verified |
| CORS | Explicit allowlist only (never `*`), `Access-Control-Allow-Credentials` only with explicit origin; Vary: Origin set when applicable. | Implemented & verified |
| Input validation | Allowlisted keys, strict types, format checks (email, 10-digit refs, token formats), integer bounds, note length; rejects control chars; validator collects errors, throws 422 with details. | Implemented & verified |
| Mass-assignment protection | Validator rejects unexpected fields (e.g. role/status/balance/ids on register). Registration forces role=customer server-side. | Implemented & verified |
| SQL injection prevention | All queries use prepared statements with bound positional params (`PDO::prepare` + bindValue). Tests confirm no raw SQL built from user input. | Implemented & verified |
| Atomicity | Transfers use `BEGIN IMMEDIATE` (write lock), guarded UPDATEs (`balance_minor >= amount`), ledger invariant checks, idempotency keys; rollback on failure. | Implemented & verified |
| Idempotency | `idempotency_keys` (user,key) store request_hash; same payload replays original (200), different payload → 409. Header required and format-checked. | Implemented & verified |
| Append-only | Triggers prevent UPDATE/DELETE on `transfers`, `ledger_entries`, `audit_events`. Also app-level design enforces. | Implemented & verified |
| Redacted audit | `AuditRedactor` enforces event-type allowlists for meta keys; strips keys matching forbidden patterns (pass/token/etc), truncates strings, redacts bearer tokens, rejects unsupported types. Staff view omits meta; admin sees full (audited). | Implemented & verified |
| Rate limiting | `RateLimiter` fixed-window counters in DB, returns Retry-After seconds; applied to register/login/transfer/profile/admin ops. | Implemented & verified |
| Transport/security headers | CSP (default-src 'none'; frame-ancestors 'none'), X-Content-Type-Options nosniff, X-Frame-Options DENY, Referrer-Policy no-referrer, no-store cache, Permissions-Policy restrictive, HSTS optional, X-Powered-By removed. | Implemented & verified |
| Trust boundaries | IP from X-Forwarded-For only if peer is trusted proxy; browser client detection; Origin checks; public routes vs protected. | Implemented & verified |

## 12. Frontend and Backend Integration

- **Transport**: REST-like JSON over HTTP. All non-GET browser requests use `fetch` with `credentials: 'include'` and `X-Requested-With: XMLHttpRequest`. Vite dev proxy forwards `/api` to backend unchanged.
- **Envelope**: Backend returns `{success, data?, error?, request_id}`. Frontend unwraps `data` on success, maps `error` on failure.
- **Auth model**: Session cookie (HttpOnly) — no tokens in JS. Identity comes from `GET /me` (auth service). 
- **Contract alignment**: Frontend API clients (`customer-app/src/services/api.js`, `admin-app/src/services/api.js`) expect exact field names from backend (e.g. `account_ref`, `balance_minor`, `source_balance_minor`, ledger fields). Integration test `integration/e2e.mjs` bundles real frontend modules against real backend to verify wire format.
- **Separation**: Route guards are UX only; backend enforces authZ/authN on every endpoint. Customer/admin apps are separate apps hitting separate auth paths/cookie namespaces.

## 13. Installation and Configuration

### Requirements
- PHP 8.2+ with `ext-pdo_sqlite`, `ext-mbstring`
- Node.js + npm (for frontend)
- SQLite 3

### Backend Setup
```bash
cd backend
# copy .env if needed (defaults secure)
[ -f .env ] || cp .env.example .env
php bin/migrate.php  # creates storage/vault24.sqlite, idempotent
php bin/seed.php     # creates demo users (blocked if APP_ENV=production)
php -S 127.0.0.1:8000 -t public public/index.php  # serve
```

### Frontend Setup
```bash
# customer
cd frontend/customer-app
npm install
npm run dev  # http://localhost:5173, proxies /api to :8000

# admin
cd frontend/admin-app
npm install
npm run dev  # http://localhost:5174, proxies /api to :8000
```

### All-in-one (dev)
```bash
./dev.sh  # migrates/seeds backend, installs frontend deps if missing, starts API + both frontends
```

### Configuration (.env)
See `backend/.env.example`. Key vars: `APP_ENV`, `DB_PATH`, `LOG_PATH`, `CORS_ALLOWED_ORIGINS`, `TRUSTED_PROXIES`, `COOKIE_SECURE` (true behind HTTPS), `ENABLE_HSTS`, `SESSION_IDLE_SECONDS`, `SESSION_ABSOLUTE_SECONDS`, `MAX_TRANSFER_MINOR`. Never commit `.env`.

### Demo Credentials (fictional, published as in README)
| Role | Email | Password |
|---|---|---|
| customer | alice@vault24.test | Alice-Demo-Pass-24! (5,000.00 VLT) |
| customer | bob@vault24.test | Bob-Demo-Pass-2024! (2,500.00 VLT) |
| staff | staff@vault24.test | Staff-Demo-Pass-24! |
| admin | admin@vault24.test | Admin-Demo-Pass-24! |

## 14. Testing and Troubleshooting

### Backend Unit/Feature Tests
```bash
cd backend
php tests/run.php        # run all 48 tests
php tests/run.php login  # filter by name
```
Tests use isolated temp SQLite DB per test case (`TestApp`), fast Argon2 params in testing, and the test harness.

### Integration E2E
Requires backend and frontends available as configured. See `integration/run-e2e.sh` and `integration/e2e.mjs` (bundles real frontend API modules, simulates browser cookie jar).

### Troubleshooting
- **Backend won’t start**: check PHP version/extensions (`php -m | grep -E 'pdo_sqlite|mbstring'`), ensure `storage/` writable, check `.env` paths.
- **CORS/CSRF errors in browser**: ensure `CORS_ALLOWED_ORIGINS` includes app origins and backend allows them; browser must send `X-Requested-With`. Cookie auth requires same-origin via proxy or correct origins.
- **Port conflicts**: backend 8000, customer 5173, admin 5174. Kill conflicting processes if needed.
- **Migrations**: `migrate.php` is idempotent; adding `note` column handled automatically.

## 15. Limitations and Recommended Improvements

### Limitations (by inspection)
- PHP built-in server is for development only (not production). Production needs HTTPS, proper web server (nginx/Apache), `cookie_secure=true`, `ENABLE_HSTS=true`, trusted proxies configured.
- SQLite only; concurrency model relies on `BEGIN IMMEDIATE` (write serialization). For multi-process/clustered deployments, consider DB-specific locking (SELECT FOR UPDATE) and different storage.
- Rate limiting uses fixed-window counters in DB (coarse under some burst patterns); could use sliding window or Redis.
- Audit meta is allowlisted per event but scope is limited; security events computed from audit records (post-hoc). 
- CommonPasswords blocklist is small (demo-level). Production should use larger breached-password datasets.
- No password reset/change flows (only name update). No email verification, MFA, device tracking.
- Frontend route guards are UX-only (backend is authoritative — this is correct, but worth emphasizing).

### Recommended Improvements (pragmatic)
- Enforce HTTPS in production, enable HSTS and Secure cookies.
- Expand password policy and breach checking.
- Add password change (with re-auth) and reset flows.
- Consider sliding-window rate limiting; add structured request logging separate from app log.
- Add database constraints/indexes review and query performance monitoring as needed.
- Add automated browser E2E (Playwright/Cypress) against running stack.

## 16. Glossary of Technical Terms

- **API (Application Programming Interface)**: A defined way for programs to communicate (here, HTTP JSON endpoints under `/api/v1`).
- **Authorization vs Authentication**: Authentication verifies identity (who you are); authorization checks permissions (what you may do).
- **Cookie (HttpOnly, SameSite)**: Browser-stored token sent automatically; HttpOnly prevents JS access; SameSite=Strict reduces CSRF risk.
- **CORS (Cross-Origin Resource Sharing)**: Controls whether browser allows cross-origin requests; here allowlisted and paired with credentials carefully.
- **CSRF (Cross-Site Request Forgery)**: Trick forcing browser to make unwanted request; mitigated by SameSite + X-Requested-With + Origin checks.
- **Envelope**: Standard response shape `{success, data|error, request_id}`.
- **Idempotency**: Making the same request multiple times produce the same effect (critical for transfers).
- **Ledger (double-entry style)**: `ledger_entries` records debit/credit per transfer to maintain balanced invariants.
- **Modular monolith**: Single deployable app with clear module boundaries (Auth, Transfers, Audit, Policies, etc.).
- **Prepared statements (parameter binding)**: SQL with placeholders to prevent SQL injection.
- **Principal**: Authenticated identity object (userId, publicId, role, sessionId) derived server-side.
- **PSR-4**: Autoloading standard mapping namespaces to directories.
- **Rate limiting vs Throttling**: Rate limiting caps requests over time; login throttling is progressive lockout after failures.
- **SQLite WAL**: Write-Ahead Logging for better concurrency.
- **Transaction (BEGIN IMMEDIATE)**: Atomic unit of work; IMMEDIATE takes write lock early to serialize conflicting writes.

## 17. Conclusion

VAULT24 is a concrete, security-conscious prototype implemented as a plain PHP modular monolith with two React frontends. The codebase demonstrates clear trust boundaries (server derives identity/authorization, never trusts client), defense-in-depth (validation, allowlists, CSRF/CORS, throttling/rate limiting, atomicity, append-only audit with redaction), and verifiability (48 passing backend tests, integration contract tests). All critical behaviors described above are directly verifiable in the source files under `backend/src`, `backend/bin`, `backend/tests`, `frontend/*`, and `integration/`.

## 18. Appendix

### Key Endpoints (from Kernel)
- `GET /health` (public) → `{"success":true,"data":{"status":"ok"}}`
- `POST /api/v1/auth/register` (public, throttled 10/h/IP) → creates customer
- `POST /api/v1/auth/login` (public, throttled 60/m/IP) → login (cookie or token)
- `POST /api/v1/admin/auth/login` (public, throttled) → staff/admin login
- `POST /api/v1/auth/logout`, `GET /api/v1/me`, `PATCH /api/v1/me/profile` (any authenticated)
- `GET /api/v1/account`, `GET /api/v1/transactions` (customer)
- `POST /api/v1/transfers` (customer, throttled, requires Idempotency-Key)
- `GET /api/v1/transfers/{reference}` (owner of either leg or staff/admin)
- Admin: `GET /api/v1/admin/customers`, `GET /api/v1/admin/audit-events`, `GET /api/v1/admin/security-events` (staff/admin); `GET/POST /api/v1/admin/staff`, lock/unlock (admin only)

### Important Files
- Backend: `public/index.php`, `src/autoload.php`, `src/Http/Kernel.php`, `src/Http/Request.php`, `src/Http/Response.php`, `src/Http/Validator.php`, `src/Http/SessionCookie.php`, `src/Support/Config.php`, `src/Support/Database.php`, `src/Support/Logger.php`, `src/Support/RateLimiter.php`, `src/Support/Money.php`, `src/Auth/*`, `src/Audit/*`, `src/Transfers/TransferService.php`, `src/Policies/TransferPolicy.php`, `src/Controllers/*`, `bin/migrate.php`, `bin/seed.php`, `database/schema.sql`
- Frontend: `customer-app/src/services/api.js`, `admin-app/src/services/api.js`, `customer-app/src/App.jsx`, `admin-app/src/App.jsx`, `*/src/context/AuthContext.jsx`, `*/vite.config.js`
- Tests: `backend/tests/Harness.php`, `backend/tests/run.php`, `backend/tests/Feature/*`, `integration/e2e.mjs`

### Security Notes (Evidence-based)
- Server never trusts client for role, source account, balances, or ownership.
- Cookie-based browser auth uses HttpOnly+SameSite+Origin checks; credentials only sent with explicit allowlist.
- All writes in transfers are atomic and invariant-checked; ledger/transfers/audit immutable by DB triggers.
- Generic error messages and timing-safe comparisons prevent enumeration.
- Audit trail is allowlisted/redacted; staff cannot see metadata/balances as enforced server-side (verified in API behavior).
