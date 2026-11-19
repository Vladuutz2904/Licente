import { kv, keyFor } from '../lib/kv.js';

// GET /api/check?domain=clientul-tau.com
// Raspuns: { valid: true/false, reason: "...", expires: 1234567890 }
export default async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');

  const domain = (req.query.domain || '').toString();
  if (!domain) {
    return res.status(400).json({ valid: false, reason: 'missing_domain' });
  }

  try {
    const record = await kv.get(keyFor(domain));

    if (!record) {
      return res.status(200).json({ valid: false, reason: 'not_found' });
    }

    if (record.revoked) {
      return res.status(200).json({ valid: false, reason: 'revoked' });
    }

    if (record.expires && Date.now() > record.expires) {
      return res.status(200).json({ valid: false, reason: 'expired', expires: record.expires });
    }

    return res.status(200).json({ valid: true, expires: record.expires || null });
  } catch (err) {
    // Daca serverul pica sau KV e indisponibil, NU blocam clientul instant -
    // lasam panoul sa decida (vezi license_check.php: "fail open" cu grace period).
    return res.status(500).json({ valid: false, reason: 'server_error' });
  }
}
