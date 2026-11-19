import { kv, keyFor } from '../../lib/kv.js';

// POST /api/admin/license
// Header: x-admin-secret: <ADMIN_SECRET din Vercel env>
// Body JSON: { "domain": "client.com", "days": 30, "note": "plata noiembrie" }
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  }

  const secret = req.headers['x-admin-secret'];
  if (!secret || secret !== process.env.ADMIN_SECRET) {
    return res.status(401).json({ ok: false, reason: 'unauthorized' });
  }

  const { domain, days, note } = req.body || {};
  if (!domain || !days) {
    return res.status(400).json({ ok: false, reason: 'missing_domain_or_days' });
  }

  const expires = Date.now() + Number(days) * 24 * 60 * 60 * 1000;

  const record = {
    expires,
    revoked: false,
    note: note || '',
    updated: Date.now(),
  };

  await kv.set(keyFor(domain), record);

  return res.status(200).json({ ok: true, domain, expires, expiresReadable: new Date(expires).toISOString() });
}
