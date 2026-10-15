# VAULT24 — Secure Backend (fictional prototype)

A security-first banking API built from the *VAULT24 Secure Backend Architecture* document:
modular monolith · one relational DB · server-side authorization · atomic, idempotent transfers ·
redacted audit trail · a repeatable test suite that proves each control.

> **Fictional only.** All users, balances and transfers are simulated. Not production banking software.

## Stack (and why)
Plain **PHP 8.2+** (no Composer dependencies) + **SQLite** (PDO). The document allows "Laravel or an
equivalent mature framework"; this build is dependency-free so that it runs and is verifiable anywhere
with only `php`. The module/policy/service boundaries match the document's layout, so porting to Laravel is
mechanical (see *Porting to Laravel*).

## Quick start
```bash
cp .env.example .env            # optional
php bin/migrate.php             # creates storage/vault24.sqlite (idempotent, never drops data)
php bin/seed.php                # fictional demo users (refuses when APP_ENV=production)
php -S 127.0.0.1:8000 -t public public/index.php
php tests/run.php               # 48 tests, ~5s  (php tests/run.php idempot  -> filter by name)
```
Requires `ext-pdo_sqlite`, `ext-mbstring`. The built-in server is for the demo only; in deployment put
nginx/Apache + PHP-FPM behind HTTPS, serve only `public/`, and set `ENABLE_HSTS=true`, `TRUSTED_PROXIES`.

### Fictional demo credentials (published on purpose)
| Role | Email | Password |
|---|---|---|
| customer | alice@vault24.test | `Alice-Demo-Pass-24!` (5,000.00 VLT) |
| customer | bob@vault24.test | `Bob-Demo-Pass-2024!` (2,500.00 VLT) |
| staff | staff@vault24.test | `Staff-Demo-Pass-24!` |
| admin | admin@vault24.test | `Admin-Demo-Pass-24!` |

## API (all under `/api/v1`, JSON)
Two ways to authenticate (same server-side sessions underneath):
- **API / mobile clients** — `POST /auth/login` returns a token; send `Authorization: Bearer <token>`.
- **Browser apps** (the React front-ends) — send `X-Requested-With: XMLHttpRequest`; the token is set **only** as an
  `HttpOnly; SameSite=Strict` cookie and never appears in the JSON body. Cookie-authenticated writes must carry that header and a
  trusted `Origin` (CSRF defence). The customer app uses cookie `v24_session`, the admin app `v24_admin_session`
  (a customer cookie is never read on `/admin/*`, and vice versa).

| Method & path | Access | Notes |
|---|---|---|
| `POST /auth/register` | public, 10/h/IP | `name,email,password` only. Role=customer, balance=0 and the **10-digit account number** are assigned server-side |
| `POST /auth/login` | public, throttled | generic failure; progressive lockout per account + IP |
| `POST /auth/logout` · `GET /me` | any role | revoke session · safe profile `{id,name,email,role}` |
| `PATCH /me/profile` | any role | only `name` is editable |
| `GET /account` | customer | own account + simulated balance |
| `GET /transactions?page&per_page` | customer | own ledger entries (≤ 50/page) with `direction, amount_minor, note, status, counterparty_name` |
| `POST /transfers` | customer, 10/min | `destination_account_ref` (10 digits), `amount_minor` (int), optional `note` (≤ 60), `currency`; header **`Idempotency-Key`** required; returns `source_balance_minor` |
| `GET /transfers/{reference}` | owner of either leg, staff, admin | everyone else gets 404 |
| `POST /admin/auth/login` · `/logout` · `GET /admin/auth/me` | staff/admin only | admin portal session (separate cookie); customers get a generic 401 |
| `GET /admin/customers` | staff, admin | **staff get `balance_minor: null`** |
| `GET /admin/audit-events` | staff, admin | filters `event_type,outcome,actor_user_id`; **staff get no `meta`**; every read is audited |
| `GET /admin/security-events` | staff, admin | failures/denials with server-computed `severity` (high/medium/low) |
| `GET /admin/staff` · `POST /admin/staff` | admin | create requires `current_password` (re-authentication) |
| `POST /admin/users/{id}/lock` · `/unlock` | admin | requires `reason` + `current_password`; revokes sessions |
| `GET /health` | public | `{"status":"ok"}` only |

Responses: `{"success":true,"data":…,"request_id":"…"}` or `{"success":false,"error":{"code","message","details?"},"request_id":"…"}`.
Status codes used: 200/201/204, 400, 401, 403, 404, 405, 409, 413, 415, 422, 429, 500.

## Layout
```
public/index.php          front controller          src/Http/        Kernel (pipeline), Router, Request, Response, Validator
src/Auth/                 AuthService, PasswordHasher, LoginThrottle, Principal
src/Transfers/            TransferService  <- the ONLY writer of balances
src/Policies/             TransferPolicy (object-level authorization)
src/Audit/                AuditService + AuditRedactor (central allowlist + deny-patterns)
src/Controllers/          thin controllers       database/schema.sql   constraints + immutability triggers
tests/Feature/            security & integrity tests     tests/worker.php  child process for concurrency tests
```

## Control → implementation → proof
| Document requirement | Implementation | Test(s) |
|---|---|---|
| Deny by default, least privilege | Every non-`public` route needs a valid token **and** a listed role (`Kernel::dispatch`) | *role matrix* |
| IDOR / ownership | Identity from the session (`Principal`), ownership-scoped queries, `TransferPolicy`, 404 not 403 | *IDOR ×2* |
| Privilege escalation / mass assignment | Role set server-side; strict field allowlists reject `role,status,balance,…` | *registration*, *mass assignment* |
| Password storage | Argon2id (bcrypt fallback) via `password_hash`; 12-char min, local blocklist | *passwords stored…* |
| Credential attacks | Generic errors, dummy-hash timing equalisation, per-account + per-IP progressive lockout | *login ×3* |
| Sessions | 256-bit random bearer tokens, stored as SHA-256, idle (15 min) + absolute (8 h) expiry, logout/lock revokes | *sessions*, *logout* |
| Atomic transfers | One `BEGIN IMMEDIATE` transaction: debit, credit, transfer, 2 ledger rows, idempotency row | *happy path*, *atomic rollback* |
| Race / overspend / replay | Write lock + guarded `UPDATE … WHERE balance>=?` + CHECK ≥ 0 + unique `(user_id,key)`; **real multi-process tests** | *concurrency ×3*, *idempotency ×2* |
| Money correctness | Integer minor units; JSON ints only (floats/strings rejected); static check bans float/rounding functions | *input validation*, *money* |
| Immutable ledger & audit | DB triggers reject UPDATE/DELETE on `ledger_entries`, `transfers`, `audit_events` | *database constraints* |
| Audit redaction | Per-event field allowlist + deny patterns (pass/token/secret/auth/hash/bearer); who/what/which/request-id/outcome | *audit redaction ×2* |
| Errors | No stack/SQL/paths to clients; details only in `storage/logs` (never bodies/headers) | *error handling* |
| Injection / malformed input | Prepared statements only; content-type, JSON depth, 16 KB body, length, enum and pagination limits | *injection*, *pagination*, *request hygiene*, *no raw SQL* |
| CSRF (cookie sessions) | `HttpOnly; SameSite=Strict` cookie + mandatory `X-Requested-With` + `Origin` allow-list on cookie-authenticated writes; separate admin cookie | *CSRF*, *browser login*, *customer cookie vs admin routes* |
| CORS / headers / transport | Explicit origin allowlist (no `*`), CSP `default-src 'none'`, `nosniff`, `DENY`, `no-store`, optional HSTS; bearer tokens ⇒ no cookie-CSRF surface | *CORS*, *security headers* |
| Admin misuse | Admin-only routes, reason code, re-authentication, self-lock refused, every action audited | *admin lock/unlock*, *admin audit* |

Tests are not decorative: during development they caught a real bug (a rejected transfer left a SQLite
transaction open because `PDO::inTransaction()` doesn't track a raw `BEGIN`), and mutation checks
(disabling the ownership policy, the idempotency check, or the write lock) make the matching tests fail.

## Threat model (assumed attacker)
Can send arbitrary HTTP, tamper with IDs/amounts, replay, guess credentials, run concurrent requests, and
read any response they are allowed to see. Goals defended: unauthorised disclosure, privilege escalation,
incorrect balances. See the table above for each threat's control and its test.

## Known limitations / trade-offs (be upfront with judges)
- **SQLite, single-writer.** Correct and simple for a prototype; `BEGIN IMMEDIATE` is the lock. For MySQL/PostgreSQL
  port the schema and use `SELECT … FOR UPDATE` on both accounts in ascending id order (noted in `Database.php`).
- **Registration reveals "email cannot be used"** (422) — a deliberate usability trade-off; login does not enumerate.
- Audit table is not tamper-proof against a fully privileged DB admin (triggers stop the app; production needs external log shipping).
- Browser sessions rely on cookies; a production deployment must serve the apps and API over HTTPS (`COOKIE_SECURE=true`, `ENABLE_HSTS=true`).
- No MFA, email recovery, fraud scoring, or real payment rails. Demo passwords are public and fictional; `seed.php` refuses production.
- A `locked` user's per-account login lockout can be triggered by an attacker (standard throttling trade-off); lock durations are capped at 15 min and failed-while-locked attempts are not recorded.

## Porting to Laravel (if your team must submit in Laravel)
`Kernel` routes → `routes/api.php` + middleware (`auth:sanctum`, `role:`, `throttle:`) · `Validator` → Form Requests (`prohibited` / `Rule::in`) ·
`TransferPolicy` → Policy · `TransferService` → same class with `DB::transaction` + `lockForUpdate()` · `AuthService` → Sanctum tokens + `Hash::` ·
`AuditService/Redactor` → unchanged · `schema.sql` → migrations (keep CHECK constraints and triggers). The tests map 1:1 to Pest/PHPUnit feature tests.

## Submission checklist (document §16)
- [x] Only fictional identities/balances · [x] No secrets committed (`.env` ignored) · [x] Registration cannot create privileged users or balances
- [x] Server-side ownership checks · [x] Explicit admin authorization · [x] Hashing, throttling, logout revocation
- [x] Atomic + idempotent transfers with DB constraints · [x] No float money · [x] Redacted audit · [x] Safe errors
- [x] Tests for IDOR, escalation, insufficient funds, rollback, replay, concurrency
- [ ] **Fill in before submitting:** AI-use disclosure, team contributions, final commit SHA (`git rev-parse HEAD`)

### AI-use disclosure (template — edit to match your team's actual use)
This scaffold was generated with Anthropic's Claude from the VAULT24 architecture document, then executed and tested in a sandbox
(48 passing backend tests, 41 frontend↔backend contract checks, repeated runs and mutation checks). The team must review, understand and be able to explain every component.

### Team contributions (template)
| Member | Owned components |
|---|---|
| _name_ | Auth/sessions, throttling |
| _name_ | Transfers, ledger, idempotency |
| _name_ | Policies, admin, audit |
| _name_ | Tests, README, deployment |
