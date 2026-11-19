import crypto from 'crypto';

export const SESSION_COOKIE_NAME = 'admin_session';
const SESSION_TTL_MS = 12 * 60 * 60 * 1000; // 12 ore
export const SESSION_TTL_SECONDS = SESSION_TTL_MS / 1000;

function getSecret() {
  // Foloseste ADMIN_SECRET (acelasi din Environment Variables).
  // Daca nu e setat, folosim un fallback - dar SETEAZA-L in Vercel!
  return process.env.ADMIN_SECRET || 'schimba-acest-secret';
}

function sign(payload) {
  return crypto.createHmac('sha256', getSecret()).update(payload).digest('hex');
}

export function createSessionToken() {
  const expires = Date.now() + SESSION_TTL_MS;
  const payload = String(expires);
  return `${payload}.${sign(payload)}`;
}

export function verifySessionToken(token) {
  if (!token || typeof token !== 'string') return false;
  const dot = token.lastIndexOf('.');
  if (dot === -1) return false;

  const payload = token.slice(0, dot);
  const sig = token.slice(dot + 1);
  const expected = sign(payload);

  // comparatie in timp constant, ca sa evitam timing attacks
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return false;

  const expires = Number(payload);
  return Boolean(expires) && Date.now() < expires;
}

export function parseCookies(req) {
  const header = req.headers.cookie || '';
  const out = {};
  header.split(';').forEach((part) => {
    const idx = part.indexOf('=');
    if (idx === -1) return;
    const key = part.slice(0, idx).trim();
    const val = part.slice(idx + 1).trim();
    if (key) out[key] = decodeURIComponent(val);
  });
  return out;
}

export function isAuthenticated(req) {
  const cookies = parseCookies(req);
  return verifySessionToken(cookies[SESSION_COOKIE_NAME]);
}

export function sessionCookieHeader(token) {
  return `${SESSION_COOKIE_NAME}=${token}; Max-Age=${SESSION_TTL_SECONDS}; Path=/; HttpOnly; Secure; SameSite=Strict`;
}

export function clearedCookieHeader() {
  return `${SESSION_COOKIE_NAME}=; Max-Age=0; Path=/; HttpOnly; Secure; SameSite=Strict`;
}
