import { api } from './api';
import { clearDeviceData } from './deviceData';
import { deriveCredential, normalize, validPassphrase } from './passphrase';
import { realtime } from './realtime/client';

/**
 * Deleting an account (server/app/Identity/Erasure.php): on the server first,
 * then — when it was this device's own — everything on the device, and a
 * fresh start. A reload, not a re-render: lib/device.ts keeps the old
 * device id in memory.
 */
export async function deleteAccount(words?: string): Promise<{ deleted: boolean; self: boolean }> {
  // Out of the room first: rejoining would only make a new identity row.
  realtime.leave();
  const body = words !== undefined && validPassphrase(normalize(words)) ? deriveCredential(normalize(words)) : undefined;
  const result = await api<{ deleted: boolean; self: boolean }>('/me', { method: 'DELETE', body });
  if (result.self) {
    await clearDeviceData();
    window.location.assign('/');
  }
  return result;
}
