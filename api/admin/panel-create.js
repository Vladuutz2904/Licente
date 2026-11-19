import { kv, keyFor } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';

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

  const expires = Date.now() + Number(days) * 24 * 60 * 60 * 1000;
  const record = { expires, revoked: false, note: note || '', updated: Date.now() };

  await kv.set(keyFor(domain), record);

  return res.status(200).json({ ok: true, domain, expires });
}
