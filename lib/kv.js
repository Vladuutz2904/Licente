import { kv } from '@vercel/kv';

// O licenta e stocata ca: license:<domeniu> => { expires: <timestamp ms>, revoked: bool, note: string }
export function keyFor(domain) {
  return `license:${domain.toLowerCase().trim()}`;
}

export { kv };
