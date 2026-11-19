import { generateLicenseKey } from './keygen.js';

const DAY = 24 * 60 * 60 * 1000;

// O licenta e stocata ca  lic:<CHEIE> => {
//   key, domain|null, expires (timestamp ms | null = PERMANENTA),
//   revoked (bool = dezactivata), note, created, updated, lastSeen, lastDomain
// }
export const recKey = (key) => `lic:${normKey(key)}`;
export const normKey = (k) => String(k || '').trim().toUpperCase();

export function normDomain(d) {
  return String(d || '')
    .toLowerCase()
    .trim()
    .replace(/^https?:\/\//, '')
    .replace(/\/.*$/, '')
    .replace(/:\d+$/, '')
    .replace(/^www\./, '');
}

export function statusOf(rec) {
  if (!rec) return 'missing';
  if (rec.revoked) return 'revoked';
  if (rec.expires && Date.now() > rec.expires) return 'expired';
  return 'valid';
}

// days: numar de zile; permanent: true => fara expirare
export function computeExpires(days, permanent) {
  if (permanent) return null;
  const n = Number(days);
  if (!Number.isFinite(n) || n <= 0) return undefined; // invalid
  return Date.now() + Math.round(n * DAY);
}

export async function createUniqueKey(kv) {
  for (let i = 0; i < 8; i++) {
    const key = generateLicenseKey();
    if (!(await kv.exists(recKey(key)))) return key;
  }
  throw new Error('Nu am putut genera o cheie unica.');
}

// Citeste toate licentele; migreaza automat inregistrarile vechi (license:<domeniu>)
export async function listAll(kv) {
  // migrare licente vechi legate doar de domeniu
  const legacy = await kv.keys('license:*');
  for (const lk of legacy) {
    const old = (await kv.get(lk)) || {};
    const key = old.key && /^[A-Z0-9-]+$/.test(old.key) ? old.key : await createUniqueKey(kv);
    const rec = {
      key,
      domain: normDomain(lk.replace(/^license:/, '')),
      expires: old.expires || null,
      revoked: !!old.revoked,
      note: old.note || '',
      created: old.created || old.updated || Date.now(),
      updated: Date.now(),
    };
    await kv.set(recKey(key), rec);
    await kv.del(lk);
  }

  const keys = await kv.keys('lic:*');
  const out = [];
  for (const k of keys) {
    const rec = await kv.get(k);
    if (rec) out.push({ ...rec, key: rec.key || k.replace(/^lic:/, '') });
  }
  out.sort((a, b) => (b.updated || 0) - (a.updated || 0));
  return out;
}
