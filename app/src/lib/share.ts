import i18n from '@/i18n';
import { hasPlugin, plugin } from './native';

export type ShareResult = 'shared' | 'copied' | 'none';

/** A cancelled share sheet is the listener's choice, not an error (Android says "Share canceled"). */
function cancelled(e: unknown): boolean {
  return (e as { name?: string })?.name === 'AbortError' || /cancel/i.test(String((e as { message?: string })?.message ?? e));
}

/**
 * Share the station: the phone's share sheet in the store apps, the browser's
 * where it has one, otherwise the link is copied.
 */
export async function shareStation(): Promise<ShareResult> {
  const url = `${window.location.origin}/`;
  const title = i18n.t('share.title');
  const text = i18n.t('share.text');
  if (hasPlugin('Share')) {
    const m = await plugin('Share');
    if (m) {
      try {
        await m.Share.share({ title, text, url, dialogTitle: i18n.t('share.button') });
        return 'shared';
      } catch (e) {
        if (cancelled(e)) return 'none';
      }
    }
  }
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title, text, url });
      return 'shared';
    } catch (e) {
      if (cancelled(e)) return 'none';
    }
  }
  try {
    await navigator.clipboard.writeText(url);
    return 'copied';
  } catch {
    return 'none';
  }
}
