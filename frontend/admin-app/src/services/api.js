import * as mock from './mock';

// Talks to the Vault24 PHP backend (/api/v1). The admin portal uses its own endpoints and its own
// HttpOnly cookie (v24_admin_session), separate from the customer app's cookie.
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

function toApiError(status, json, retryAfter) {
  const e = json?.error || {};
  const d = e.details || {};
  const requestId = json?.request_id;
  const make = (code, message) => new ApiError(status, { code, message, requestId });

  if (e.code === 'validation_failed') {
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
    case 'authentication_required':
    case 'invalid_token': return make('UNAUTHENTICATED', 'Not signed in.');
    case 'forbidden': return make('FORBIDDEN', 'You do not have permission to do this.');
    case 'reauthentication_failed': return make('REAUTHENTICATION_FAILED', 'Your password is incorrect.');
    default: return make((e.code || 'ERROR').toUpperCase(), e.message);
  }
}

async function request(method, path, body) {
  let res;
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, { code: 'NETWORK', message: 'Could not reach the server. Check your connection.' });
  }
  const json = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw toApiError(res.status, json, res.headers.get('Retry-After'));
  return json?.data ?? null;
}

const mapUser = (u) => ({ id: u.id, name: u.name, email: u.email, role: u.role, createdAt: u.created_at });

const real = {
  login: async ({ email, password }) => ({ user: mapUser((await request('POST', '/admin/auth/login', { email, password })).user) }),
  logout: async () => {
    await request('POST', '/admin/auth/logout');
    return null;
  },
  me: async () => ({ user: mapUser(await request('GET', '/admin/auth/me')) }),
  // Staff receive balanceMinor = null and no audit metadata: the SERVER enforces that, not this file.
  adminCustomers: async () => {
    const d = await request('GET', '/admin/customers?per_page=100');
    return {
      customers: d.items.map((c) => ({ id: c.id, name: c.name, email: c.email, accountNumber: c.account_ref, status: c.status, balanceMinor: c.balance_minor ?? null, createdAt: c.created_at })),
    };
  },
  adminAuditLogs: async () => {
    const d = await request('GET', '/admin/audit-events?per_page=100');
    return {
      logs: d.items.map((a) => ({
        id: String(a.id), actorUserId: a.actor_user_id, actorName: a.actor_name ?? 'Anonymous', action: a.event_type,
        resourceType: a.target_type ?? '-', outcome: a.outcome, createdAt: a.created_at, requestId: a.request_id, metadata: a.meta,
      })),
    };
  },
  adminSecurityEvents: async () => {
    const d = await request('GET', '/admin/security-events');
    return {
      events: d.items.map((e) => ({
        id: e.id, severity: e.severity, type: `${e.event_type} ${e.outcome}`, actorName: e.actor_name ?? 'Anonymous',
        detail: e.detail, createdAt: e.created_at, requestId: e.request_id,
      })),
    };
  },
  staffList: async () => ({ staff: (await request('GET', '/admin/staff')).items.map(mapUser) }),
  createStaff: async ({ name, email, password, role, currentPassword }) => ({
    user: mapUser(await request('POST', '/admin/staff', { name, email, password, role, current_password: currentPassword })),
  }),
};

export const api = USE_MOCK ? mock.api : real;
