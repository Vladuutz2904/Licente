import { getKv } from '../../lib/kv.js';
import { isAuthenticated } from '../../lib/auth.js';
import { safe } from '../../lib/http.js';
import { recKey, normDomain, computeExpires, createUniqueKey } from '../../lib/licenses.js';

// POST /api/admin/panel-create  { days, permanent, domain?, note? }
// permanent=true => licenta fara expirare; altfel "days" zile de la acum.
export default safe(async function handler(req, res) {
  if (req.method !== 'POST') return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  if (!isAuthenticated(req)) return res.status(401).json({ ok: false, reason: 'unauthorized' });

  const { days, permanent, domain, note } = req.body || {};
  const expires = computeExpires(days, !!permanent);
  if (expires === undefined) {
    return res.status(400).json({ ok: false, reason: 'invalid_days', error: 'Pune un numar de zile mai mare ca 0 sau bifeaza "Permanenta".' });
  }

  const kv = getKv();
  const key = await createUniqueKey(kv);
  const record = {
    key,
    domain: normDomain(domain) || null, // gol => se leaga automat la prima folosire
    expires,                            // null = permanenta
    revoked: false,
    note: String(note || '').slice(0, 200),
    created: Date.now(),
    updated: Date.now(),
  };
  await kv.set(recKey(key), record);

  return res.status(200).json({ ok: true, key, domain: record.domain, expires, permanent: expires === null });
});
