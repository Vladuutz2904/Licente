import { createSessionToken, sessionCookieHeader } from '../../lib/auth.js';

// POST /api/admin/login  { username, password }
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ ok: false, reason: 'method_not_allowed' });
  }

  const { username, password } = req.body || {};

  // IMPORTANT: seteaza ADMIN_USER si ADMIN_PASS in Environment Variables pe Vercel.
  // Valorile de mai jos sunt doar fallback, ca sa mearga din prima - schimba-le!
  const expectedUser = process.env.ADMIN_USER || 'Vladuutz';
  const expectedPass = process.env.ADMIN_PASS || 'VladEvelina123!';

  if (username !== expectedUser || password !== expectedPass) {
    // mic delay anti brute-force
    await new Promise((r) => setTimeout(r, 400));
    return res.status(401).json({ ok: false, reason: 'invalid_credentials' });
  }

  const token = createSessionToken();
  res.setHeader('Set-Cookie', sessionCookieHeader(token));
  return res.status(200).json({ ok: true });
}
