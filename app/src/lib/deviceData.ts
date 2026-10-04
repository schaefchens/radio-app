import { DEVICE_KEYS, SESSION_KEYS } from './deviceKeys';
import { forgetReminders } from './reminders';

/** The service worker's runtime caches (program files, audio, scenery); not its precache of the app itself. */
const CACHE_PREFIX = 'arche-';

export async function clearDeviceData(): Promise<void> {
  // First, while the list still says which: a reminder must not outlive "delete".
  await forgetReminders();
  for (const [storage, keys] of [
    [globalThis.localStorage, DEVICE_KEYS],
    [globalThis.sessionStorage, SESSION_KEYS],
  ] as const) {
    for (const key of keys) {
      try {
        storage?.removeItem(key);
      } catch {
        /* storage unavailable: nothing to delete */
      }
    }
  }
  try {
    const names = (await globalThis.caches?.keys()) ?? [];
    await Promise.all(names.filter((n) => n.startsWith(CACHE_PREFIX)).map((n) => caches.delete(n)));
  } catch {
    /* no Cache Storage here */
  }
}
