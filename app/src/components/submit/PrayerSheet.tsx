import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { Done, NamePlace, PrivacyNote } from './SongRequestSheet';

export function PrayerSheet({ open, onClose, onRecord }: { open: boolean; onClose: () => void; onRecord: () => void }) {
  const { t } = useTranslation();
  const uid = useId();
  const identity = useSession((s) => s.identity);
  const lang = useSettings((s) => s.lang);
  const channel = useRadio((s) => s.engine.channel);
  const [text, setText] = useState('');
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  const [share, setShare] = useState(true);
  const submit = useSubmit(() => api('/submissions/prayer', { body: { channel, text, name, place, lang, consent_air: share } }));
  const close = (): void => {
    onClose();
    if (submit.done) {
      setText('');
      submit.reset();
    }
  };
  return (
    <BottomSheet open={open} onClose={close} title={t('submit.prayer.title')}>
      <BottomSheetBody>
        {submit.done ? (
          <Done onClose={close} />
        ) : (
          <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
              e.preventDefault();
              void submit.run();
            }}
          >
            <div>
              <label className="label" htmlFor={`${uid}-text`}>{t('prayerForm.text')}</label>
              <textarea id={`${uid}-text`} className="field min-h-[120px]" maxLength={400} value={text} onChange={(e) => setText(e.target.value)} />
              <p className="mt-1 text-xs text-ink-faint">{t('prayerForm.textHint')}</p>
            </div>
            <NamePlace name={name} place={place} setName={setName} setPlace={setPlace} />
            <label className="flex items-start gap-2 text-sm text-ink-muted">
              <input type="checkbox" className="mt-1" checked={share} onChange={(e) => setShare(e.target.checked)} />
              {t('prayerForm.showAsVoice')}
            </label>
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            <PrivacyNote />
            <div className="flex gap-2">
              <button type="submit" className="btn-primary flex-1" disabled={text.trim().length < 5 || submit.busy}>
                {submit.busy ? t('common.loading') : t('submit.send')}
              </button>
              <button type="button" className="btn-ghost" onClick={onRecord}>
                {t('prayerForm.record')}
              </button>
            </div>
          </form>
        )}
      </BottomSheetBody>
    </BottomSheet>
  );
}
