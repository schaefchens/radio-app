import { hasPlugin, plugin } from './native';

/**
 * A light tap of the phone when a reaction is given — store apps only, never
 * on the website. ImpactStyle.Light by name: the plugin's default is Heavy.
 */
type HapticsModule = typeof import('@capacitor/haptics');

let loading: Promise<HapticsModule | null> | null = null;

function load(): Promise<HapticsModule | null> {
  loading ??= plugin('Haptics');
  return loading;
}

/** Load the plugin at start, so the first tap is not late. */
export function warmHaptics(): void {
  if (hasPlugin('Haptics')) void load();
}

export function tapHaptic(): void {
  if (!hasPlugin('Haptics')) return;
  void load()
    .then((m) => m?.Haptics.impact({ style: m.ImpactStyle.Light }))
    .catch(() => {});
}
