import { getKv, keyFor } from '../lib/kv.js';
import { safe } from '../lib/http.js';
import { recKey, normKey, normDomain, statusOf } from '../lib/licenses.js';

// GET /api/check?key=CFTE-XXXX-XXXX-XXXX&domain=panou.com
// Raspuns: { valid, reason, expires|null, permanent }
// reason: ok | missing_key | not_found | revoked | expired | domain_mismatch
export default safe(async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  const q = { ...(req.query || {}), ...(req.body && typeof req.body === 'object' ? req.body : {}) };
  const key = normKey(q.key);
  const domain = normDomain(q.domain);
  const kv = getKv();

  // ---- compatibilitate: panouri vechi care trimit doar domeniul ----
  if (!key) {
    if (!domain) return res.status(400).json({ valid: false, reason: 'missing_key' });
    const legacy = await kv.get(keyFor(domain));
    if (legacy) {
      const st = statusOf(legacy);
      if (st !== 'valid') return res.status(200).json({ valid: false, reason: st, expires: legacy.expires || null });
      return res.status(200).json({ valid: true, reason: 'ok', expires: legacy.expires || null, permanent: !legacy.expires });
    }
    for (const k of await kv.keys('lic:*')) {
      const rec = await kv.get(k);
      if (rec && rec.domain === domain) {
        const st = statusOf(rec);
        if (st !== 'valid') return res.status(200).json({ valid: false, reason: st, expires: rec.expires || null });
        return res.status(200).json({ valid: true, reason: 'ok', expires: rec.expires || null, permanent: !rec.expires });
      }
    }
    return res.status(200).json({ valid: false, reason: 'not_found' });
  }

  const rec = await kv.get(recKey(key));
  if (!rec) return res.status(200).json({ valid: false, reason: 'not_found' });

  const st = statusOf(rec);
  if (st === 'revoked') return res.status(200).json({ valid: false, reason: 'revoked' });
  if (st === 'expired') return res.status(200).json({ valid: false, reason: 'expired', expires: rec.expires });

  // legare de domeniu: la prima folosire cheia se leaga de domeniul panoului
  if (domain) {
    if (rec.domain && rec.domain !== domain) {
      return res.status(200).json({ valid: false, reason: 'domain_mismatch' });
    }
    let dirty = false;
    if (!rec.domain) { rec.domain = domain; dirty = true; }
    // retinem ultima verificare (max o data la 2 minute, ca sa nu scriem la fiecare cerere)
    if (!rec.lastSeen || Date.now() - rec.lastSeen > 120000) { rec.lastSeen = Date.now(); rec.lastDomain = domain; dirty = true; }
    if (dirty) await kv.set(recKey(key), rec);
  }

  return res.status(200).json({ valid: true, reason: 'ok', expires: rec.expires || null, permanent: !rec.expires });
});
