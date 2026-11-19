import { kv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';

// GET /api/admin/panel-list  - necesita sesiune (cookie) valida
export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');

  if (!isAuthenticated(req)) {
    return res.status(401).json({ ok: false, reason: 'unauthorized' });
  }

  try {
    const keys = await kv.keys('license:*');
    const licenses = await Promise.all(
      keys.map(async (k) => {
        const record = (await kv.get(k)) || {};
        return { domain: k.replace(/^license:/, ''), ...record };
      })
    );

    licenses.sort((a, b) => (b.updated || 0) - (a.updated || 0));

    return res.status(200).json({ ok: true, licenses });
  } catch (err) {
    return res.status(500).json({ ok: false, reason: 'server_error' });
  }
}
