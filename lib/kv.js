import { Redis } from '@upstash/redis';

// Conexiune lazy: daca variabilele de mediu lipsesc, NU crapam la import
// (asta producea raspuns HTML de la Vercel => "Eroare necunoscuta" in panou).
let client = null;

export function getKv() {
  if (client) return client;
  const url =
    process.env.KV_REST_API_URL ||
    process.env.UPSTASH_REDIS_REST_URL ||
    process.env.REDIS_REST_API_URL;
  const token =
    process.env.KV_REST_API_TOKEN ||
    process.env.UPSTASH_REDIS_REST_TOKEN ||
    process.env.REDIS_REST_API_TOKEN;
  if (!url || !token) {
    const err = new Error(
      'Baza de date nu este conectata: lipsesc KV_REST_API_URL / KV_REST_API_TOKEN (Vercel -> Storage -> conecteaza Upstash Redis, apoi Redeploy).'
    );
    err.code = 'kv_not_configured';
    throw err;
  }
  client = new Redis({ url, token });
  return client;
}

// compatibilitate cu endpoint-urile vechi (license.js / revoke.js)
export function keyFor(domain) {
  return `license:${domain.toLowerCase().trim()}`;
}
