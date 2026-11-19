import { getKv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { safe } from '../../lib/http.js';
import { recKey, normKey } from '../../lib/licenses.js';

// POST /api/admin/panel-delete  { key }  -> sterge definitiv (panoul se blocheaza: not_found)
export default safe(async function handler(req, res) {
  if (req.method !== 'POST') return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  if (!isAuthenticated(req)) return res.status(401).json({ ok: false, reason: 'unauthorized' });
  const key = normKey((req.body || {}).key);
  if (!key) return res.status(400).json({ ok: false, reason: 'missing_key' });

  await getKv().del(recKey(key));
  return res.status(200).json({ ok: true, key });
});
