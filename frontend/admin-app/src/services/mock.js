// Dev-only stand-in for the Express API (admin side). Fictional data only.
// Admin: admin@vault24.test | Staff: staff@vault24.test  (Password123!)
// Note: this mock has its own data. Customers shown here are seeded, not the ones created in the customer app.
const KEY = 'vault24_admin_mock';
const VERSION = 3;
const wait = (ms = 350) => new Promise((r) => setTimeout(r, ms));
const uid = () => crypto.randomUUID();
const fail = (status, code, message) => Promise.reject(Object.assign(new Error(message), { status, code, requestId: uid().slice(0, 8) }));

function seed() {
  const now = Date.now();
  const ago = (m) => new Date(now - m * 6e4).toISOString();
  const entry = (actorUserId, actorName, action, resourceType, outcome, m, metadata = {}) => ({
    id: uid(), actorUserId, actorName, action, resourceType, outcome, createdAt: ago(m), requestId: uid().slice(0, 8), metadata,
  });
  return {
    version: VERSION,
    session: null,
    fails: {},
    users: [
      { id: 's1', name: 'Naomi Zulu', email: 'admin@vault24.test', password: 'Password123!', role: 'admin', createdAt: ago(9000) },
      { id: 's2', name: 'Chanda Mulenga', email: 'staff@vault24.test', password: 'Password123!', role: 'staff', createdAt: ago(8000) },
    ],
    customers: [
      { id: 'c1', name: 'Mwila Banda', email: 'demo@vault24.test', accountNumber: '1001000001', status: 'active', balanceMinor: 500000, createdAt: ago(7000) },
      { id: 'c2', name: 'Thandi Phiri', email: 'thandi@vault24.test', accountNumber: '1001000002', status: 'active', balanceMinor: 120000, createdAt: ago(6500) },
      { id: 'c3', name: 'Joseph Tembo', email: 'joseph@vault24.test', accountNumber: '1001000003', status: 'active', balanceMinor: 78000, createdAt: ago(3000) },
    ],
    audit: [
      entry('c1', 'Mwila Banda', 'auth.login', 'session', 'success', 300),
      entry('c2', 'Thandi Phiri', 'transfer.create', 'transfer', 'success', 2880, { amountMinor: 25000 }),
      entry(null, 'Anonymous', 'auth.login', 'session', 'failure', 95, { email: 'admin@vault24.test' }),
      entry('c3', 'Joseph Tembo', 'transfer.create', 'transfer', 'failure', 60, { code: 'INSUFFICIENT_FUNDS' }),
    ],
  };
}
const load = () => {
  const d = JSON.parse(sessionStorage.getItem(KEY) || 'null');
  return d && d.version === VERSION ? d : seed();
};
const save = (db) => sessionStorage.setItem(KEY, JSON.stringify(db));
const safeUser = (u) => ({ id: u.id, name: u.name, email: u.email, role: u.role, createdAt: u.createdAt });
const authed = (db) => db.users.find((x) => x.id === db.session) || null;

// Never log passwords or tokens.
function log(db, actor, action, resourceType, outcome, metadata = {}) {
  db.audit.unshift({ id: uid(), actorUserId: actor?.id ?? null, actorName: actor?.name ?? 'Anonymous', action, resourceType, outcome, createdAt: new Date().toISOString(), requestId: uid().slice(0, 8), metadata });
}

function guard(db, action, roles = ['staff', 'admin']) {
  const u = authed(db);
  if (!u) return { err: fail(401, 'UNAUTHENTICATED', 'Not signed in.') };
  if (!roles.includes(u.role)) {
    log(db, u, action, 'admin', 'denied');
    save(db);
    return { err: fail(403, 'FORBIDDEN', 'You do not have permission to do this.') };
  }
  return { u };
}

export const api = {
  async login({ email, password }) {
    await wait();
    const db = load();
    const e = email.trim().toLowerCase();
    const recent = (db.fails[e] || []).filter((t) => Date.now() - t < 15 * 6e4);
    if (recent.length >= 5) {
      log(db, null, 'auth.login', 'session', 'blocked', { email: e });
      save(db);
      return fail(429, 'TOO_MANY_ATTEMPTS', 'Too many failed attempts. Try again in 15 minutes.');
    }
    const u = db.users.find((x) => x.email === e && x.password === password);
    if (!u) {
      db.fails[e] = [...recent, Date.now()];
      log(db, null, 'auth.login', 'session', 'failure', { email: e });
      save(db);
      return fail(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
    }
    db.session = u.id;
    log(db, u, 'auth.login', 'session', 'success');
    save(db);
    return { user: safeUser(u) };
  },
  async logout() {
    await wait(150);
    const db = load();
    log(db, authed(db), 'auth.logout', 'session', 'success');
    db.session = null;
    save(db);
    return null;
  },
  async me() {
    await wait(150);
    const u = authed(load());
    return u ? { user: safeUser(u) } : fail(401, 'UNAUTHENTICATED', 'Not signed in.');
  },
  async adminCustomers() {
    await wait();
    const db = load();
    const { u, err } = guard(db, 'admin.view_customers');
    if (err) return err;
    log(db, u, 'admin.view_customers', 'customer', 'success');
    save(db);
    // Staff get a limited view: no balances.
    return { customers: db.customers.map((c) => ({ ...c, balanceMinor: u.role === 'admin' ? c.balanceMinor : null })) };
  },
  async adminAuditLogs() {
    await wait();
    const db = load();
    const { u, err } = guard(db, 'admin.view_audit_logs');
    if (err) return err;
    log(db, u, 'admin.view_audit_logs', 'audit_log', 'success');
    save(db);
    return { logs: db.audit.slice(0, 200).map((a) => (u.role === 'admin' ? a : { ...a, metadata: undefined })) };
  },
  async adminSecurityEvents() {
    await wait();
    const db = load();
    const { err } = guard(db, 'admin.view_security_events');
    if (err) return err;
    const severity = (a) => (a.outcome === 'blocked' || a.outcome === 'denied' ? 'high' : a.action.startsWith('transfer') ? 'medium' : 'low');
    const events = db.audit
      .filter((a) => a.outcome !== 'success')
      .map((a) => ({ id: a.id, severity: severity(a), type: `${a.action} ${a.outcome}`, actorName: a.actorName, detail: a.metadata?.email || a.metadata?.code || '', createdAt: a.createdAt, requestId: a.requestId }));
    return { events };
  },
  async staffList() {
    await wait();
    const db = load();
    const { err } = guard(db, 'admin.list_staff', ['admin']);
    if (err) return err;
    return { staff: db.users.map(safeUser) };
  },
  async createStaff({ name, email, password, role }) {
    await wait();
    const db = load();
    const { u, err } = guard(db, 'admin.create_staff', ['admin']);
    if (err) return err;
    const e = email.trim().toLowerCase();
    if (!['staff', 'admin'].includes(role)) return fail(422, 'INVALID_ROLE', 'Choose staff or admin.');
    if (db.users.some((x) => x.email === e) || db.customers.some((c) => c.email === e)) return fail(409, 'EMAIL_TAKEN', 'An account with this email already exists.');
    const user = { id: uid(), name, email: e, password, role, createdAt: new Date().toISOString() };
    db.users.push(user);
    log(db, u, 'admin.create_staff', 'user', 'success', { role, email: e });
    save(db);
    return { user: safeUser(user) };
  },
};
