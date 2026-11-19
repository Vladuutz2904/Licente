import { getKv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { safe } from '../../lib/http.js';
import { listAll } from '../../lib/licenses.js';

// GET /api/admin/panel-list
export default safe(async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  if (!isAuthenticated(req)) return res.status(401).json({ ok: false, reason: 'unauthorized' });
  const licenses = await listAll(getKv());
  return res.status(200).json({ ok: true, licenses });
});
