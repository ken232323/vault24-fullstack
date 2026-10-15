// Dev-only stand-in for the Express API (customer side). Fictional data only.
// Demo login: demo@vault24.test / Password123!   Recipient to test transfers: 1001000002
const KEY = 'vault24_customer_mock';
const VERSION = 3;
const wait = (ms = 350) => new Promise((r) => setTimeout(r, ms));
const uid = () => crypto.randomUUID();
const fail = (status, code, message) => Promise.reject(Object.assign(new Error(message), { status, code, requestId: uid().slice(0, 8) }));

function seed() {
  return {
    version: VERSION,
    session: null,
    fails: {},
    users: [
      { id: 'u1', name: 'Mwila Banda', email: 'demo@vault24.test', password: 'Password123!' },
      { id: 'u2', name: 'Thandi Phiri', email: 'thandi@vault24.test', password: 'Password123!' },
    ],
    accounts: [
      { id: 'a1', userId: 'u1', accountNumber: '1001000001', balanceMinor: 500000, currency: 'ZMW', status: 'active' },
      { id: 'a2', userId: 'u2', accountNumber: '1001000002', balanceMinor: 120000, currency: 'ZMW', status: 'active' },
    ],
    transfers: [
      { id: 't0', reference: 'V24-SEED0001', from: 'a2', to: 'a1', amountMinor: 25000, note: 'Lunch money', status: 'completed', createdAt: new Date(Date.now() - 864e5 * 2).toISOString(), key: 'seed' },
    ],
  };
}
const load = () => {
  const d = JSON.parse(sessionStorage.getItem(KEY) || 'null');
  return d && d.version === VERSION ? d : seed();
};
const save = (db) => sessionStorage.setItem(KEY, JSON.stringify(db));
const safeUser = (u) => ({ id: u.id, name: u.name, email: u.email });
const authed = (db) => db.users.find((x) => x.id === db.session) || null;
const unauth = () => fail(401, 'UNAUTHENTICATED', 'Not signed in.');

export const api = {
  async register({ name, email, accountNumber = '10' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0'), password }) {
    await wait();
    const db = load();
    const e = email.trim().toLowerCase();
    if (db.users.some((u) => u.email === e)) return fail(409, 'EMAIL_TAKEN', 'An account with this email already exists.');
    if (db.accounts.some((a) => a.accountNumber === accountNumber)) return fail(409, 'ACCOUNT_NUMBER_TAKEN', 'This account number is already registered.');
    const user = { id: uid(), name, email: e, password };
    db.users.push(user);
    db.accounts.push({ id: uid(), userId: user.id, accountNumber, balanceMinor: 100000, currency: 'ZMW', status: 'active' });
    save(db);
    return { user: safeUser(user), accountNumber };
  },
  async login({ email, password }) {
    await wait();
    const db = load();
    const e = email.trim().toLowerCase();
    const recent = (db.fails[e] || []).filter((t) => Date.now() - t < 15 * 6e4);
    if (recent.length >= 5) return fail(429, 'TOO_MANY_ATTEMPTS', 'Too many failed attempts. Try again in 15 minutes.');
    const u = db.users.find((x) => x.email === e && x.password === password);
    if (!u) {
      db.fails[e] = [...recent, Date.now()];
      save(db);
      return fail(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
    }
    db.session = u.id;
    save(db);
    return { user: safeUser(u) };
  },
  async logout() {
    await wait(150);
    const db = load();
    db.session = null;
    save(db);
    return null;
  },
  async me() {
    await wait(150);
    const u = authed(load());
    return u ? { user: safeUser(u) } : unauth();
  },
  async accounts() {
    await wait();
    const db = load();
    const u = authed(db);
    if (!u) return unauth();
    return { accounts: db.accounts.filter((a) => a.userId === u.id) };
  },
  async transactions() {
    await wait();
    const db = load();
    const u = authed(db);
    if (!u) return unauth();
    const mine = db.accounts.filter((a) => a.userId === u.id).map((a) => a.id);
    const name = (accId) => db.users.find((x) => x.id === db.accounts.find((a) => a.id === accId)?.userId)?.name ?? 'Unknown';
    const list = db.transfers
      .filter((t) => mine.includes(t.from) || mine.includes(t.to))
      .map((t) => {
        const sent = mine.includes(t.from);
        return { id: t.id, reference: t.reference, direction: sent ? 'sent' : 'received', amountMinor: t.amountMinor, counterparty: name(sent ? t.to : t.from), note: t.note, status: t.status, createdAt: t.createdAt };
      })
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    return { transactions: list };
  },
  async createTransfer({ toAccountNumber, amountMinor, note, idempotencyKey }) {
    await wait(600);
    const db = load();
    const u = authed(db);
    if (!u) return unauth();
    const from = db.accounts.find((a) => a.userId === u.id);
    const prior = db.transfers.find((t) => t.key === idempotencyKey);
    if (prior) return { transaction: { id: prior.id, reference: prior.reference, amountMinor: prior.amountMinor, status: prior.status, createdAt: prior.createdAt }, balances: { fromBalanceMinor: from.balanceMinor } };
    const to = db.accounts.find((a) => a.accountNumber === toAccountNumber);
    if (!to || to.status !== 'active') return fail(404, 'RECIPIENT_NOT_FOUND', 'We could not find an active account with that number.');
    if (to.id === from.id) return fail(422, 'SAME_ACCOUNT', 'You cannot send money to your own account.');
    if (from.balanceMinor < amountMinor) return fail(422, 'INSUFFICIENT_FUNDS', 'The transfer could not be completed.');
    from.balanceMinor -= amountMinor;
    to.balanceMinor += amountMinor;
    const t = { id: uid(), reference: 'V24-' + uid().slice(0, 8).toUpperCase(), from: from.id, to: to.id, amountMinor, note, status: 'completed', createdAt: new Date().toISOString(), key: idempotencyKey };
    db.transfers.push(t);
    save(db);
    return { transaction: { id: t.id, reference: t.reference, amountMinor, status: t.status, createdAt: t.createdAt }, balances: { fromBalanceMinor: from.balanceMinor } };
  },
  async updateProfile({ name }) {
    await wait();
    const db = load();
    const u = authed(db);
    if (!u) return unauth();
    u.name = name;
    save(db);
    return { user: safeUser(u) };
  },
};
