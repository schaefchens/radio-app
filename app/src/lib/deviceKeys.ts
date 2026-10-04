/**
 * Everything Arche Radio keeps on this device, for "Delete data on this
 * device" (lib/deviceData.ts; the privacy policy promises it).
 * tests/unit/deviceData.test.ts fails when a new stored key is missing here.
 */
export const DEVICE_KEYS = ['arche.device', 'arche.settings', 'arche.passphrase', 'arche.reactions', 'arche.reminders', 'arche.blocks', 'arche.cdn'];
/** Per tab, the "?install=1" latch (lib/pwaInstall.ts). */
export const SESSION_KEYS = ['arche.install.intent'];
