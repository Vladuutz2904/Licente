import { kv, keyFor } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';

// POST /api/admin/panel-revoke  { domain }  - necesita sesiune (cookie) valida
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  }
  if (!isAuthenticated(req)) {
    return res.status(401).json({ ok: false, reason: 'unauthorized' });
  }

  const { domain } = req.body || {};
  if (!domain) {
    return res.status(400).json({ ok: false, reason: 'missing_domain' });
  }

  const k = keyFor(domain);
  const existing = await kv.get(k);
  if (!existing) {
    return res.status(404).json({ ok: false, reason: 'not_found' });
  }

  existing.revoked = true;
  existing.updated = Date.now();
  await kv.set(k, existing);

  return res.status(200).json({ ok: true, domain });
}
