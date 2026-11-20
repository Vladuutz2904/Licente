import { getKv, keyFor } from '../lib/kv.js';
import { safe } from '../lib/http.js';
import { recKey, normKey, normDomain, statusOf } from '../lib/licenses.js';
import { signCheckResponse } from '../lib/sign.js';

// GET /api/check?key=CFTE-XXXX-XXXX-XXXX&domain=panou.com
// Raspuns: { valid, reason, expires|null, permanent, key, domain, sig }
// reason: ok | missing_key | not_found | revoked | expired | domain_mismatch
// sig: HMAC-SHA256(key|domain|valid|reason|expires) cu LICENSE_SIGN_SECRET.
//      Lipseste daca LICENSE_SIGN_SECRET nu e setat pe server.
export default safe(async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  const q = { ...(req.query || {}), ...(req.body && typeof req.body === 'object' ? req.body : {}) };
  const key = normKey(q.key);
  const domain = normDomain(q.domain);
  const kv = getKv();

  const reply = (valid, reason, extra = {}) => {
    const expires = extra.expires ?? null;
    const body = { valid, reason, expires, ...extra, key, domain };
    const sig = signCheckResponse({ key, domain, valid, reason, expires });
    if (sig) body.sig = sig;
    return res.status(200).json(body);
  };

  // ---- compatibilitate: panouri vechi care trimit doar domeniul ----
  if (!key) {
    if (!domain) return res.status(400).json({ valid: false, reason: 'missing_key' });
    const legacy = await kv.get(keyFor(domain));
    if (legacy) {
      const st = statusOf(legacy);
      if (st !== 'valid') return reply(false, st, { expires: legacy.expires || null });
      return reply(true, 'ok', { expires: legacy.expires || null, permanent: !legacy.expires });
    }
    for (const k of await kv.keys('lic:*')) {
      const rec = await kv.get(k);
      if (rec && rec.domain === domain) {
        const st = statusOf(rec);
        if (st !== 'valid') return reply(false, st, { expires: rec.expires || null });
        return reply(true, 'ok', { expires: rec.expires || null, permanent: !rec.expires });
      }
    }
    return reply(false, 'not_found');
  }

  const rec = await kv.get(recKey(key));
  if (!rec) return reply(false, 'not_found');

  const st = statusOf(rec);
  if (st === 'revoked') return reply(false, 'revoked');
  if (st === 'expired') return reply(false, 'expired', { expires: rec.expires });

  // legare de domeniu: la prima folosire cheia se leaga de domeniul panoului
  if (domain) {
    if (rec.domain && rec.domain !== domain) {
      return reply(false, 'domain_mismatch');
    }
    let dirty = false;
    if (!rec.domain) { rec.domain = domain; dirty = true; }
    // retinem ultima verificare (max o data la 2 minute, ca sa nu scriem la fiecare cerere)
    if (!rec.lastSeen || Date.now() - rec.lastSeen > 120000) { rec.lastSeen = Date.now(); rec.lastDomain = domain; dirty = true; }
    if (dirty) await kv.set(recKey(key), rec);
  }

  return reply(true, 'ok', { expires: rec.expires || null, permanent: !rec.expires });
});
