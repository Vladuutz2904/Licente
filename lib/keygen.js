import crypto from 'crypto';

// Genereaza o cheie gen "CFTE-X7K2-9PQR-4MNB"
export function generateLicenseKey(prefix = 'CFTE') {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // fara litere/cifre confuze (I,O,0,1)
  const block = () => {
    const bytes = crypto.randomBytes(4);
    let out = '';
    for (let i = 0; i < 4; i++) out += chars[bytes[i] % chars.length];
    return out;
  };
  return `${prefix}-${block()}-${block()}-${block()}`;
}
