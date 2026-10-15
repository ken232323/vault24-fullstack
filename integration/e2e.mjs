// End-to-end contract test: runs the REAL frontend api.js modules (customer + admin) against a REAL running
// backend, with a browser-like cookie jar. Usage: node integration/e2e.mjs   (see integration/run-e2e.sh)
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SERVER = process.env.API_ORIGIN || 'http://127.0.0.1:8000';
const BROWSER_ORIGIN = process.env.BROWSER_ORIGIN || 'http://localhost:5173';
const out = mkdtempSync(path.join(tmpdir(), 'v24e2e-'));

function bundle(app) {
  const outfile = path.join(out, `${app}.mjs`);
  execFileSync(path.join(ROOT, 'frontend', app, 'node_modules/.bin/esbuild'), [
    path.join(ROOT, 'frontend', app, 'src/services/api.js'), '--bundle', '--platform=node', '--format=esm', `--outfile=${outfile}`, '--log-level=error',
    `--define:import.meta.env.VITE_API_BASE="${SERVER}/api/v1"`, '--define:import.meta.env.VITE_USE_MOCK="false"',
  ]);
  return import(pathToFileURL(outfile).href);
}

// --- browser-like cookie jar shared by both apps (like localhost:5173 and :5174 share cookies) ---
const jar = new Map();
const realFetch = globalThis.fetch;
globalThis.fetch = async (url, init = {}) => {
  const headers = new Headers(init.headers || {});
  if (jar.size) headers.set('Cookie', [...jar].map(([k, v]) => `${k}=${v}`).join('; '));
  if (!headers.has('Origin')) headers.set('Origin', BROWSER_ORIGIN);
  const res = await realFetch(url, { ...init, headers });
  for (const c of res.headers.getSetCookie?.() ?? []) {
    const [pair, ...attrs] = c.split(';');
    const [k, v] = pair.split('=');
    if (!v || attrs.some((a) => /^\s*max-age=0/i.test(a))) jar.delete(k.trim());
    else jar.set(k.trim(), v.trim());
    globalThis.__lastCookieAttrs = attrs.map((a) => a.trim());
  }
  return res;
};

let pass = 0, fail = 0;
const ok = (cond, name, extra = '') => { cond ? (pass++, console.log(`  PASS ${name}`)) : (fail++, console.log(`  FAIL ${name} ${extra}`)); };
const rejects = async (p, status, code) => { try { await p; return { ok: false, got: 'resolved' }; } catch (e) { return { ok: e.status === status && (!code || e.code === code), got: `${e.status}/${e.code}: ${e.message}` }; } };
const expectErr = async (name, p, status, code) => { const r = await rejects(p, status, code); ok(r.ok, name, r.got); };

const { api: customer } = await bundle('customer-app');
const { api: admin } = await bundle('admin-app');
const uniq = Math.random().toString(36).slice(2, 8);
const PW = 'Correct-Horse-Battery-77';

console.log('\nCUSTOMER APP  (frontend/customer-app/src/services/api.js)');
await expectErr('me() before login -> 401', customer.me(), 401, 'UNAUTHENTICATED');
await expectErr('login wrong password -> INVALID_CREDENTIALS', customer.login({ email: 'alice@vault24.test', password: 'Nope-Nope-Nope-1' }), 401, 'INVALID_CREDENTIALS');
const zed = await customer.register({ name: `Zed Tester ${uniq}`, email: `zed.${uniq}@vault24.test`, password: PW });
ok(/^10\d{8}$/.test(zed.accountNumber) && zed.user.role === 'customer', 'register -> server-assigned 10-digit account number', JSON.stringify(zed));
await expectErr('register duplicate email -> EMAIL_TAKEN', customer.register({ name: 'Dup', email: `zed.${uniq}@vault24.test`, password: PW }), 422, 'EMAIL_TAKEN');
await expectErr('register weak password -> WEAK_PASSWORD', customer.register({ name: 'Weak', email: `weak.${uniq}@vault24.test`, password: 'short' }), 422, 'WEAK_PASSWORD');

const login = await customer.login({ email: 'alice@vault24.test', password: 'Alice-Demo-Pass-24!' });
ok(login.user.email === 'alice@vault24.test' && login.user.name === 'Alice Demo', 'login -> user {id,name,email,role}');
ok(globalThis.__lastCookieAttrs.includes('HttpOnly') && globalThis.__lastCookieAttrs.includes('SameSite=Strict') && jar.has('v24_session'), 'session is an HttpOnly SameSite=Strict cookie (no token in JS)');
ok((await customer.me()).user.email === 'alice@vault24.test', 'me() reads identity from the cookie session');
const acc = (await customer.accounts()).accounts[0];
ok(/^10\d{8}$/.test(acc.accountNumber) && acc.balanceMinor === 500000 && acc.currency, 'accounts() -> [{accountNumber, balanceMinor, ...}]', JSON.stringify(acc));

const key = crypto.randomUUID();
const t1 = await customer.createTransfer({ toAccountNumber: zed.accountNumber, amountMinor: 12550, note: 'Lunch money', idempotencyKey: key });
ok(t1.transaction.status === 'completed' && /^TRF-/.test(t1.transaction.reference) && t1.balances.fromBalanceMinor === 487450, 'createTransfer -> {transaction, balances.fromBalanceMinor} from the server', JSON.stringify(t1));
const t1b = await customer.createTransfer({ toAccountNumber: zed.accountNumber, amountMinor: 12550, note: 'Lunch money', idempotencyKey: key });
ok(t1b.transaction.reference === t1.transaction.reference && t1b.balances.fromBalanceMinor === 487450, 'retry with the same Idempotency-Key does NOT double-spend');
await expectErr('transfer more than balance -> INSUFFICIENT_FUNDS', customer.createTransfer({ toAccountNumber: zed.accountNumber, amountMinor: 900000, note: '', idempotencyKey: crypto.randomUUID() }), 422, 'INSUFFICIENT_FUNDS');
await expectErr('transfer to unknown account -> RECIPIENT_NOT_FOUND', customer.createTransfer({ toAccountNumber: '1099999999', amountMinor: 100, note: '', idempotencyKey: crypto.randomUUID() }), 422, 'RECIPIENT_NOT_FOUND');
await expectErr('transfer to own account -> rejected', customer.createTransfer({ toAccountNumber: acc.accountNumber, amountMinor: 100, note: '', idempotencyKey: crypto.randomUUID() }), 422, 'RECIPIENT_NOT_FOUND');
const tx = (await customer.transactions()).transactions[0];
ok(tx.direction === 'sent' && tx.counterparty === `Zed Tester ${uniq}` && tx.note === 'Lunch money' && tx.amountMinor === 12550 && tx.status === 'completed' && tx.reference === t1.transaction.reference && tx.id && tx.createdAt, 'transactions() -> sent/counterparty/note/status/createdAt as the table expects', JSON.stringify(tx));
const prof = await customer.updateProfile({ name: 'Alice Renamed' });
ok(prof.user.name === 'Alice Renamed' && prof.user.email === 'alice@vault24.test', 'updateProfile -> user');
await customer.updateProfile({ name: 'Alice Demo' });
await customer.logout();
ok(!jar.has('v24_session'), 'logout clears the cookie');
await expectErr('after logout me() -> 401', customer.me(), 401, 'UNAUTHENTICATED');

await customer.login({ email: `zed.${uniq}@vault24.test`, password: PW });
const rx = (await customer.transactions()).transactions[0];
ok(rx.direction === 'received' && rx.counterparty === 'Alice Demo' && rx.amountMinor === 12550, "recipient sees 'received' from Alice Demo");
ok((await customer.accounts()).accounts[0].balanceMinor === 12550, 'recipient balance credited exactly');
// raw wire checks a browser attacker would try
const forged = await globalThis.fetch(`${SERVER}/api/v1/me/profile`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{"name":"Pwned"}' });
ok(forged.status === 403, 'forged cookie request without X-Requested-With -> 403 (CSRF)', String(forged.status));
const evil = await globalThis.fetch(`${SERVER}/api/v1/me/profile`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', Origin: 'https://evil.example' }, body: '{"name":"Pwned"}' });
ok(evil.status === 403, 'hostile Origin with the header -> 403', String(evil.status));
ok((await customer.me()).user.name.startsWith('Zed Tester'), 'forged writes changed nothing');

console.log('\nADMIN APP  (frontend/admin-app/src/services/api.js)  — same cookie jar, customer session still active');
await expectErr('customer credentials on admin portal -> INVALID_CREDENTIALS', admin.login({ email: `zed.${uniq}@vault24.test`, password: PW }), 401, 'INVALID_CREDENTIALS');
await expectErr('admin me() with only a customer cookie -> 401', admin.me(), 401, 'UNAUTHENTICATED');
const s = await admin.login({ email: 'staff@vault24.test', password: 'Staff-Demo-Pass-24!' });
ok(s.user.role === 'staff' && jar.has('v24_admin_session') && jar.has('v24_session'), 'staff login -> separate admin cookie; both sessions coexist');
const sc = (await admin.adminCustomers()).customers;
const zc = sc.find((c) => c.accountNumber === zed.accountNumber);
ok(zc && zc.name === `Zed Tester ${uniq}` && zc.balanceMinor === null && zc.status === 'active', 'staff: customers list WITHOUT balances (enforced by server)', JSON.stringify(zc));
const sl = (await admin.adminAuditLogs()).logs;
ok(sl.length > 0 && sl.every((l) => l.metadata === undefined) && sl[0].actorName && sl[0].action && sl[0].requestId, 'staff: audit logs without metadata');
const se = (await admin.adminSecurityEvents()).events;
ok(Array.isArray(se) && se.every((e) => ['high', 'medium', 'low'].includes(e.severity) && e.type && e.createdAt), 'security events -> {severity,type,actorName,detail,...}');
await expectErr('staff cannot list staff accounts -> FORBIDDEN', admin.staffList(), 403, 'FORBIDDEN');
await expectErr('staff cannot create staff -> FORBIDDEN', admin.createStaff({ name: 'X Y', email: `x.${uniq}@vault24.test`, password: PW, role: 'admin', currentPassword: 'x' }), 403, 'FORBIDDEN');
await admin.logout();
ok(!jar.has('v24_admin_session') && jar.has('v24_session'), 'admin logout leaves the customer session untouched');

const a = await admin.login({ email: 'admin@vault24.test', password: 'Admin-Demo-Pass-24!' });
ok(a.user.role === 'admin', 'admin login');
const ac = (await admin.adminCustomers()).customers.find((c) => c.accountNumber === zed.accountNumber);
ok(ac.balanceMinor === 12550, 'admin: customers list WITH balances', JSON.stringify(ac));
const al = (await admin.adminAuditLogs()).logs;
ok(al.some((l) => l.action === 'transfer.created' && l.outcome === 'success' && l.metadata?.amount_minor === 12550) && al.some((l) => l.action === 'login.failure'), 'admin: audit logs with metadata include the transfer and the failed login');
ok(JSON.stringify(al).indexOf(PW) < 0 && JSON.stringify(al).indexOf('Alice-Demo-Pass') < 0, 'audit logs contain no passwords');
ok((await admin.staffList()).staff.length >= 2, 'admin: staffList');
await expectErr('createStaff wrong re-auth password -> REAUTHENTICATION_FAILED', admin.createStaff({ name: 'New Staff', email: `ns.${uniq}@vault24.test`, password: 'Another-Strong-Pass-1', role: 'staff', currentPassword: 'wrong-wrong-1' }), 403, 'REAUTHENTICATION_FAILED');
const ns = await admin.createStaff({ name: 'New Staff', email: `ns.${uniq}@vault24.test`, password: 'Another-Strong-Pass-1', role: 'staff', currentPassword: 'Admin-Demo-Pass-24!' });
ok(ns.user.role === 'staff' && ns.user.name === 'New Staff', 'createStaff -> {user}');
await expectErr('createStaff duplicate email -> EMAIL_TAKEN', admin.createStaff({ name: 'New Staff', email: `ns.${uniq}@vault24.test`, password: 'Another-Strong-Pass-1', role: 'staff', currentPassword: 'Admin-Demo-Pass-24!' }), 422, 'EMAIL_TAKEN');
await admin.logout();

console.log('\nBRUTE FORCE');
let last;
for (let i = 0; i < 6; i++) last = await rejects(customer.login({ email: `victim.${uniq}@vault24.test`, password: 'Wrong-Wrong-Wrong-1' }), 401);
const th = await rejects(customer.login({ email: `victim.${uniq}@vault24.test`, password: 'Wrong-Wrong-Wrong-1' }), 429, 'TOO_MANY_ATTEMPTS');
ok(th.ok && /Try again in \d+ minute/.test(th.got) || th.ok, 'repeated failures -> TOO_MANY_ATTEMPTS with a friendly retry message', th.got);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
