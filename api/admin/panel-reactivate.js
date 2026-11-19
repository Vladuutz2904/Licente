import { getKv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { safe } from '../../lib/http.js';
import { recKey, normKey } from '../../lib/licenses.js';

// POST /api/admin/panel-reactivate  { key }  -> ACTIVEAZA licenta la loc
export default safe(async function handler(req, res) {
  if (req.method !== 'POST') return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  if (!isAuthenticated(req)) return res.status(401).json({ ok: false, reason: 'unauthorized' });
  const key = normKey((req.body || {}).key);
  if (!key) return res.status(400).json({ ok: false, reason: 'missing_key' });

  const kv = getKv();
  const rec = await kv.get(recKey(key));
  if (!rec) return res.status(404).json({ ok: false, reason: 'not_found' });
  rec.revoked = false;
  rec.updated = Date.now();
  await kv.set(recKey(key), rec);
  return res.status(200).json({ ok: true, key });
});
