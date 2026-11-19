import { kv, keyFor } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';

// POST /api/admin/panel-delete  { domain }  - sterge definitiv inregistrarea
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

  await kv.del(keyFor(domain));
  return res.status(200).json({ ok: true, domain });
}
