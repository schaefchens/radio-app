import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { closeInstallCard, installPlatform, promptInstall, useInstallStore } from '@/lib/pwaInstall';
import { useSettings } from '@/store/settings';

/**
 * The `?install=1` sheet: one button that opens the browser's install dialog,
 * or written steps where there is no install API. A visitor from a QR code is
 * a first visitor, so it waits until the welcome dialog is closed — two
 * modals at once, and the sheet would sit under the dialog's top layer.
 */
export function InstallSheet() {
  const { t } = useTranslation();
  // Primitives one at a time: an object selector is a new reference per write.
  const open = useInstallStore((s) => s.open);
  const promptable = useInstallStore((s) => s.promptable);
  const timedOut = useInstallStore((s) => s.timedOut);
  const installed = useInstallStore((s) => s.installed);
  const welcomed = useSettings((s) => s.welcomed);
  // Mounted only for a visit that asked, and kept once it did, so closing
  // slides the sheet away instead of cutting it off.
  const [asked, setAsked] = useState(open);
  if (open && !asked) setAsked(true);
  if (!asked) return null;

  // Before the wait is over nothing shows: in Chrome the event is usually
  // already parked, and elsewhere a placeholder would only flash.
  const shown = open && welcomed && (installed || promptable || timedOut);
  return (
    <BottomSheet open={shown} onClose={closeInstallCard} title={installed ? t('install.done.title') : t('install.title')}>
      <BottomSheetBody>
        <div className="flex flex-col gap-4">
          {installed ? (
            <>
              <p className="text-sm text-ink">{t('install.done.body')}</p>
              <button type="button" className="btn-primary" onClick={closeInstallCard}>
                {t('common.close')}
              </button>
            </>
          ) : (
            <>
              <p className="text-sm text-ink-muted">{t('install.intro')}</p>
              {promptable ? (
                <div className="flex gap-2">
                  {/* `void`, not `await`: an async handler spends the gesture
                      before prompt() runs (see promptInstall). */}
                  <button type="button" className="btn-primary flex-1" onClick={() => void promptInstall()}>
                    {t('install.action')}
                  </button>
                  <button type="button" className="btn-ghost" onClick={closeInstallCard}>
                    {t('install.notNow')}
                  </button>
                </div>
              ) : (
                <>
                  <p className="text-sm text-ink">{t(`install.steps.${installPlatform()}`)}</p>
                  <button type="button" className="btn-ghost" onClick={closeInstallCard}>
                    {t('common.close')}
                  </button>
                </>
              )}
            </>
          )}
        </div>
      </BottomSheetBody>
    </BottomSheet>
  );
}
