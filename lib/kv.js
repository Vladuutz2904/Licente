import { Redis } from '@upstash/redis';

// Upstash Redis prin Vercel Marketplace injecteaza automat
// KV_REST_API_URL si KV_REST_API_TOKEN in Environment Variables.
export const kv = Redis.fromEnv();

// O licenta e stocata ca: license:<domeniu> => { expires: <timestamp ms>, revoked: bool, note: string }
export function keyFor(domain) {
  return `license:${domain.toLowerCase().trim()}`;
}
