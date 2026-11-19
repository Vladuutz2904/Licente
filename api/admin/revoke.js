import { getKv, keyFor } from '../../lib/kv.js';
const kv = new Proxy({}, { get: (_, p) => (...a) => getKv()[p](...a) });

// POST /api/admin/revoke
// Header: x-admin-secret: <ADMIN_SECRET din Vercel env>
// Body JSON: { "domain": "client.com" }
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  }

  const secret = req.headers['x-admin-secret'];
  if (!secret || secret !== process.env.ADMIN_SECRET) {
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

  return res.status(200).json({ ok: true, domain, revoked: true });
}
