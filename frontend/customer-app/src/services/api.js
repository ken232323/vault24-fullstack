import * as mock from './mock';

// All requests go to the Vault24 PHP backend (/api/v1). In dev, Vite proxies /api -> http://localhost:8000
// so the HttpOnly session cookie stays same-origin. This file is the ONLY place that knows the wire format:
// it unwraps the { success, data, request_id } envelope and maps backend fields to what the pages use.
const BASE = import.meta.env.VITE_API_BASE || '/api/v1';
export const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

export class ApiError extends Error {
  constructor(status, error) {
    super(error?.message || 'Something went wrong. Try again.');
    this.status = status;
    this.code = error?.code;
    this.requestId = error?.requestId;
  }
}

// Backend error codes -> the codes/messages the UI was designed around.
function toApiError(status, json, retryAfter) {
  const e = json?.error || {};
  const d = e.details || {};
  const requestId = json?.request_id;
  const make = (code, message) => new ApiError(status, { code, message, requestId });

  if (e.code === 'validation_failed') {
    if (d.destination_account_ref) return make('RECIPIENT_NOT_FOUND', 'We could not find an active account with that number, or it cannot receive this transfer.');
    if (d.email === 'This email address cannot be used.') return make('EMAIL_TAKEN', 'An account with this email already exists.');
    if (d.password) return make('WEAK_PASSWORD', d.password);
    const first = Object.entries(d)[0];
    return make('VALIDATION', first ? `${first[0].replace(/_/g, ' ')}: ${first[1]}` : e.message);
  }
  switch (e.code) {
    case 'invalid_credentials': return make('INVALID_CREDENTIALS', 'Email or password is incorrect.');
    case 'too_many_attempts': {
      const mins = retryAfter ? Math.max(1, Math.ceil(Number(retryAfter) / 60)) : null;
      return make('TOO_MANY_ATTEMPTS', `Too many failed attempts. Try again ${mins ? `in ${mins} minute${mins > 1 ? 's' : ''}` : 'later'}.`);
    }
    case 'rate_limited': return make('RATE_LIMITED', 'Too many requests. Please wait a moment and try again.');
    case 'insufficient_funds': return make('INSUFFICIENT_FUNDS', 'The transfer could not be completed.');
    case 'authentication_required':
    case 'invalid_token': return make('UNAUTHENTICATED', 'Not signed in.');
    case 'forbidden': return make('FORBIDDEN', 'You do not have permission to do this.');
    case 'account_unavailable': return make('ACCOUNT_UNAVAILABLE', 'Your account cannot send transfers right now.');
    default: return make((e.code || 'ERROR').toUpperCase(), e.message);
  }
}

// Session = HttpOnly cookie set by the server (JS never sees a token). X-Requested-With switches the
// server into cookie mode and is also its CSRF check for state-changing requests.
async function request(method, path, body, extraHeaders = {}) {
  let res;
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...extraHeaders },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, { code: 'NETWORK', message: 'Could not reach the server. Check your connection.' });
  }
  const json = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw toApiError(res.status, json, res.headers.get('Retry-After'));
  return json?.data ?? null;
}

const mapUser = (u) => ({ id: u.id, name: u.name, email: u.email, role: u.role });

const real = {
  // The server assigns the 10-digit account number; it is returned so the UI can show it.
  register: async ({ name, email, password }) => {
    const d = await request('POST', '/auth/register', { name, email, password });
    return { user: mapUser(d.user), accountNumber: d.account_ref };
  },
  login: async ({ email, password }) => ({ user: mapUser((await request('POST', '/auth/login', { email, password })).user) }),
  logout: async () => {
    await request('POST', '/auth/logout');
    return null;
  },
  me: async () => ({ user: mapUser(await request('GET', '/me')) }),
  accounts: async () => {
    const a = await request('GET', '/account');
    return { accounts: [{ id: a.account_ref, accountNumber: a.account_ref, balanceMinor: a.balance_minor, currency: a.currency, status: a.status }] };
  },
  transactions: async () => {
    const d = await request('GET', '/transactions?per_page=50');
    return {
      transactions: d.items.map((t) => ({
        id: t.reference,
        reference: t.reference,
        direction: t.direction === 'debit' ? 'sent' : 'received',
        amountMinor: t.amount_minor,
        counterparty: t.counterparty_name,
        note: t.note,
        status: t.status,
        createdAt: t.created_at,
      })),
    };
  },
  createTransfer: async ({ toAccountNumber, amountMinor, note, idempotencyKey }) => {
    const body = { destination_account_ref: toAccountNumber, amount_minor: amountMinor };
    if (note) body.note = note;
    const d = await request('POST', '/transfers', body, { 'Idempotency-Key': idempotencyKey });
    return {
      transaction: { id: d.reference, reference: d.reference, amountMinor: d.amount_minor, status: d.status, createdAt: d.created_at },
      balances: { fromBalanceMinor: d.source_balance_minor },
    };
  },
  updateProfile: async ({ name }) => ({ user: mapUser(await request('PATCH', '/me/profile', { name })) }),
};

export const api = USE_MOCK ? mock.api : real;
