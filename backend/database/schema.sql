-- VAULT24 schema (SQLite). All data is fictional. Money = integer minor units.
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
  email         TEXT NOT NULL UNIQUE,                       -- normalised (trimmed, lower-case)
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL CHECK (role IN ('customer','staff','admin')),
  status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','locked')),
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE IF NOT EXISTS accounts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL UNIQUE REFERENCES users(id),
  account_ref   TEXT NOT NULL UNIQUE,
  currency      TEXT NOT NULL DEFAULT 'VLT' CHECK (currency = 'VLT'),
  balance_minor INTEGER NOT NULL DEFAULT 0 CHECK (balance_minor >= 0),
  status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','frozen')),
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);

CREATE TABLE IF NOT EXISTS sessions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id),
  token_hash   TEXT NOT NULL UNIQUE,                        -- sha256 of the bearer token; token itself is never stored
  created_at   INTEGER NOT NULL,
  last_seen_at INTEGER NOT NULL,
  expires_at   INTEGER NOT NULL,
  revoked_at   INTEGER
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

CREATE TABLE IF NOT EXISTS transfers (
  id                     INTEGER PRIMARY KEY AUTOINCREMENT,
  reference              TEXT NOT NULL UNIQUE,
  source_account_id      INTEGER NOT NULL REFERENCES accounts(id),
  destination_account_id INTEGER NOT NULL REFERENCES accounts(id),
  amount_minor           INTEGER NOT NULL CHECK (amount_minor > 0),
  currency               TEXT NOT NULL CHECK (currency = 'VLT'),
  status                 TEXT NOT NULL CHECK (status = 'completed'),
  note                   TEXT NOT NULL DEFAULT '' CHECK (length(note) <= 60),
  created_at             TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  CHECK (source_account_id <> destination_account_id)
);
CREATE INDEX IF NOT EXISTS idx_transfers_src ON transfers(source_account_id);
CREATE INDEX IF NOT EXISTS idx_transfers_dst ON transfers(destination_account_id);

CREATE TABLE IF NOT EXISTS ledger_entries (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  transfer_id  INTEGER NOT NULL REFERENCES transfers(id),
  account_id   INTEGER NOT NULL REFERENCES accounts(id),
  direction    TEXT NOT NULL CHECK (direction IN ('debit','credit')),
  amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  UNIQUE (transfer_id, direction)
);
CREATE INDEX IF NOT EXISTS idx_ledger_account ON ledger_entries(account_id, id);

CREATE TABLE IF NOT EXISTS idempotency_keys (
  key          TEXT NOT NULL,
  user_id      INTEGER NOT NULL REFERENCES users(id),
  request_hash TEXT NOT NULL,
  transfer_id  INTEGER NOT NULL REFERENCES transfers(id),
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  PRIMARY KEY (user_id, key)
);

CREATE TABLE IF NOT EXISTS audit_events (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_user_id INTEGER,
  event_type    TEXT NOT NULL,
  target_type   TEXT,
  target_id     TEXT,
  outcome       TEXT NOT NULL CHECK (outcome IN ('success','failure','denied')),
  request_id    TEXT,
  meta          TEXT NOT NULL DEFAULT '{}',                 -- allowlisted + redacted JSON
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_type ON audit_events(event_type, id);

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  bucket     TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_bucket ON login_attempts(bucket, created_at);

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  hits         INTEGER NOT NULL,
  PRIMARY KEY (bucket, window_start)
);

-- Append-only guarantees enforced by the database itself (defence in depth for the app-level rule).
CREATE TRIGGER IF NOT EXISTS ledger_no_update BEFORE UPDATE ON ledger_entries
  BEGIN SELECT RAISE(ABORT, 'ledger entries are immutable'); END;
CREATE TRIGGER IF NOT EXISTS ledger_no_delete BEFORE DELETE ON ledger_entries
  BEGIN SELECT RAISE(ABORT, 'ledger entries are immutable'); END;
CREATE TRIGGER IF NOT EXISTS transfers_no_update BEFORE UPDATE ON transfers
  BEGIN SELECT RAISE(ABORT, 'transfers are immutable'); END;
CREATE TRIGGER IF NOT EXISTS transfers_no_delete BEFORE DELETE ON transfers
  BEGIN SELECT RAISE(ABORT, 'transfers are immutable'); END;
CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit_events
  BEGIN SELECT RAISE(ABORT, 'audit events are append-only'); END;
CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit_events
  BEGIN SELECT RAISE(ABORT, 'audit events are append-only'); END;
