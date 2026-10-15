// Money is always integer minor units (ngwee). K25.50 -> 2550. Never use floats for balances.
export function toMinor(input) {
  const [whole, frac = ''] = String(input).trim().split('.');
  return parseInt(whole, 10) * 100 + parseInt((frac + '00').slice(0, 2), 10);
}

export function formatMoney(minor) {
  const sign = minor < 0 ? '-' : '';
  const abs = Math.abs(minor) / 100;
  return `${sign}K${abs.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export const formatDate = (iso) =>
  new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
