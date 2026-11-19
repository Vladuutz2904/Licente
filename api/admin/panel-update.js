import { getKv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { safe } from '../../lib/http.js';
import { recKey, normKey, normDomain } from '../../lib/licenses.js';

const DAY = 24 * 60 * 60 * 1000;

// POST /api/admin/panel-update  { key, ...campuri }
//  active: true|false        -> activeaza / dezactiveaza
//  permanent: true           -> fara expirare
//  days: N                   -> temporara, expira peste N zile de acum
//  addDays: N                -> prelungeste cu N zile (din expirarea curenta sau de acum, ce e mai tarziu)
//  note: "text"              -> schimba nota
//  domain: "x.com" | ""      -> seteaza / elibereaza domeniul legat
export default safe(async function handler(req, res) {
  if (req.method !== 'POST') return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  if (!isAuthenticated(req)) return res.status(401).json({ ok: false, reason: 'unauthorized' });

  const body = req.body || {};
  const key = normKey(body.key);
  if (!key) return res.status(400).json({ ok: false, reason: 'missing_key' });

  const kv = getKv();
  const rec = await kv.get(recKey(key));
  if (!rec) return res.status(404).json({ ok: false, reason: 'not_found' });

  if (typeof body.active === 'boolean') rec.revoked = !body.active;

  if (body.permanent === true) {
    rec.expires = null;
  } else if (body.days !== undefined && body.days !== null && body.days !== '') {
    const n = Number(body.days);
    if (!Number.isFinite(n) || n <= 0) return res.status(400).json({ ok: false, reason: 'invalid_days', error: 'Numar de zile invalid.' });
    rec.expires = Date.now() + Math.round(n * DAY);
  } else if (body.addDays !== undefined) {
    const n = Number(body.addDays);
    if (!Number.isFinite(n) || n <= 0) return res.status(400).json({ ok: false, reason: 'invalid_days', error: 'Numar de zile invalid.' });
    const base = rec.expires && rec.expires > Date.now() ? rec.expires : Date.now();
    rec.expires = base + Math.round(n * DAY);
  }

  if (typeof body.note === 'string') rec.note = body.note.slice(0, 200);
  if (typeof body.domain === 'string') rec.domain = normDomain(body.domain) || null;

  rec.updated = Date.now();
  await kv.set(recKey(key), rec);
  return res.status(200).json({ ok: true, license: rec });
});
