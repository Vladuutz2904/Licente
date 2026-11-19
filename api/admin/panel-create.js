import { kv, keyFor } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { generateLicenseKey } from '../../lib/keygen.js';

// POST /api/admin/panel-create  { domain, days, note }  - necesita sesiune (cookie) valida
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  }
  if (!isAuthenticated(req)) {
    return res.status(401).json({ ok: false, reason: 'unauthorized' });
  }

  const { domain, days, note } = req.body || {};
  if (!domain || !days) {
    return res.status(400).json({ ok: false, reason: 'missing_domain_or_days' });
  }

  const k = keyFor(domain);
  const existing = await kv.get(k);
  // Pastram aceeasi cheie daca reinnoim o licenta existenta pe acelasi domeniu;
  // generam una noua doar daca domeniul e nou.
  const licenseKey = existing?.key || generateLicenseKey();

  const expires = Date.now() + Number(days) * 24 * 60 * 60 * 1000;
  const record = {
    key: licenseKey,
    expires,
    revoked: false,
    note: note || '',
    created: existing?.created || Date.now(),
    updated: Date.now(),
  };

  await kv.set(k, record);

  return res.status(200).json({ ok: true, domain, key: licenseKey, expires });
}
