import crypto from 'crypto';

/**
 * Semneaza raspunsul /api/check, ca panoul sa stie ca vine chiar de la noi
 * si nu de la un server fals (pus de client in locul LICENSE_API_URL).
 *
 * Secretul se pune o singura data in Vercel -> Settings -> Environment
 * Variables, sub numele LICENSE_SIGN_SECRET, si ACELASI secret se pune in
 * config.php al panoului (constanta LICENSE_SIGN_SECRET).
 *
 * Daca secretul nu e setat aici, intoarcem null si check.js NU adauga "sig"
 * in raspuns - panoul vechi (fara verificare de semnatura) continua sa
 * mearga la fel ca pana acum, doar nesemnat.
 */
function getSignSecret() {
  return process.env.LICENSE_SIGN_SECRET || '';
}

export function signingEnabled() {
  return getSignSecret() !== '';
}

/** Payload-ul semnat trebuie sa fie identic, in aceeasi ordine, de ambele parti. */
function canonicalPayload({ key, domain, valid, reason, expires }) {
  return [
    String(key || ''),
    String(domain || ''),
    valid ? '1' : '0',
    String(reason || ''),
    expires === null || expires === undefined ? '' : String(expires),
  ].join('|');
}

export function signCheckResponse(fields) {
  const secret = getSignSecret();
  if (!secret) return null;
  const payload = canonicalPayload(fields);
  return crypto.createHmac('sha256', secret).update(payload).digest('hex');
}
