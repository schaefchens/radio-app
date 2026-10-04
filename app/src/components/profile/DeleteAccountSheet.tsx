import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import { deleteAccount } from '@/lib/account';
import { useSession } from '@/store/session';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';

/**
 * What deleting the account does, before it is done: what goes at once, and
 * what stays for a while (the privacy policy's retention periods). `words`:
 * delete the account these 12 words open (the web page for someone without
 * the app), instead of this device's.
 */
export function DeleteAccountSheet({ open, onClose, words, onDone }: { open: boolean; onClose: () => void; words?: string; onDone?: () => void }) {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const staff = identity?.role === 'moderator' || identity?.role === 'admin';
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const r = await deleteAccount(words);
      // This device's own account: the page reloads. Another one (by its words): done here.
      if (!r.self) onDone?.();
    } catch (e) {
      setError(errorText(e instanceof ApiError ? e.code : 'generic'));
      setBusy(false);
    }
  };

  return (
    <BottomSheet open={open} onClose={onClose} title={t('account.title')}>
      <BottomSheetBody>
        <div className="flex flex-col gap-3 text-sm">
          <p className="font-semibold text-ink">{t('account.goes')}</p>
          <ul className="list-disc pl-5 text-ink-muted">
            <li>{t('account.goesIdentity')}</li>
            <li>{t('account.goesSubmissions')}</li>
            <li>{t('account.goesVoices')}</li>
            <li>{t('account.goesDevice')}</li>
          </ul>
          <p className="font-semibold text-ink">{t('account.stays')}</p>
          <p className="text-ink-muted">{t('account.staysText')}</p>
          {staff && !words && <p className="rounded-xl border border-warn/40 bg-warn/10 px-3 py-2 text-ink">{t('account.staff')}</p>}
          {error && <p className="text-heart">{error}</p>}
          <div className="flex flex-wrap gap-2 pt-1">
            <button type="button" className="btn bg-heart text-white hover:brightness-110" disabled={busy} onClick={() => void run()}>
              {t('account.confirm')}
            </button>
            <button type="button" className="btn-ghost" disabled={busy} onClick={onClose}>
              {t('common.cancel')}
            </button>
          </div>
        </div>
      </BottomSheetBody>
    </BottomSheet>
  );
}
