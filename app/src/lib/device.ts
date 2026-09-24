/**
 * The anonymous device identity: a random id + secret minted on first run and
 * kept in localStorage. The server stores only HMACs of both, and creates an
 * identity row lazily the first time one is needed. A passphrase (profile)
 * can later link this device to a durable identity; logging out mints a fresh
 * device rather than re-pointing anything.
 */

const KEY = 'arche.device';

export interface Device {
  id: string;
  secret: string;
}

let cached: Device | null = null;

function hex(bytes: Uint8Array): string {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function uuidV4(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6]! & 0x0f) | 0x40;
  b[8] = (b[8]! & 0x3f) | 0x80;
  const h = hex(b);
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export function mintDevice(): Device {
  return { id: uuidV4(), secret: hex(crypto.getRandomValues(new Uint8Array(32))) };
}

export function getDevice(): Device {
  if (cached) return cached;
  try {
    const raw = localStorage.getItem(KEY);
    if (raw) {
      const d = JSON.parse(raw) as Partial<Device>;
      if (typeof d.id === 'string' && typeof d.secret === 'string' && /^[0-9a-f]{64}$/.test(d.secret)) {
        cached = { id: d.id, secret: d.secret };
        return cached;
      }
    }
  } catch {
    /* private mode or corrupted value: mint a new one */
  }
  return resetDevice();
}

/** A brand-new anonymous device (used on logout). */
export function resetDevice(): Device {
  cached = mintDevice();
  try {
    localStorage.setItem(KEY, JSON.stringify(cached));
  } catch {
    /* storage unavailable: the device lives for this session only */
  }
  return cached;
}

export function deviceHeaders(): Record<string, string> {
  const d = getDevice();
  return { 'X-Arche-Id': d.id, 'X-Arche-Secret': d.secret };
}
