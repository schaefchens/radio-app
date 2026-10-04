import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Going to the background stops the radio in the store apps (YouTube's terms),
 * and must never do so on the website: a desktop listener switching tabs
 * keeps the music.
 */

function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
  document.dispatchEvent(new Event('visibilitychange'));
}

afterEach(() => {
  setVisibility('visible');
  delete (window as { androidBridge?: unknown }).androidBridge;
  delete (window as { Capacitor?: unknown }).Capacitor;
  delete document.documentElement.dataset.native;
  vi.resetModules();
});

async function load() {
  return import('@/lib/native');
}

describe('the app going to the background', () => {
  it('in the Android app: once per trip, and back again', async () => {
    Object.assign(window, { androidBridge: {}, Capacitor: { PluginHeaders: [] } });
    const native = await load();
    const away = vi.fn();
    const back = vi.fn();
    native.onAppBackground(away);
    native.onAppForeground(back);
    native.initNative();
    expect(document.documentElement.dataset.native).toBe('android');

    setVisibility('hidden');
    setVisibility('hidden');
    expect(away).toHaveBeenCalledTimes(1);
    setVisibility('visible');
    expect(back).toHaveBeenCalledTimes(1);
  });

  it('on the website: never', async () => {
    const native = await load();
    const away = vi.fn();
    native.onAppBackground(away);
    native.initNative();
    expect(document.documentElement.dataset.native).toBeUndefined();
    setVisibility('hidden');
    expect(away).not.toHaveBeenCalled();
  });
});
