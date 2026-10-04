import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { Done, NameOrAnonymous, PrivacyNote } from './VideoRequestSheet';
import { RulesCheckbox } from '@/components/common/RulesConsent';
import { acceptRules, useRulesNeeded } from '@/lib/rulesConsent';

export function PrayerSheet({ open, onClose, onRecord }: { open: boolean; onClose: () => void; onRecord: () => void }) {
  const { t } = useTranslation();
  const uid = useId();
  const identity = useSession((s) => s.identity);
  const lang = useSettings((s) => s.lang);
  const channel = useRadio((s) => s.engine.channel);
  // A prayer hour shows every request on its wall once it is read out; the box decides the wall after the hour.
  const prayerHour = useRadio((s) => s.engine.program?.format === 'prayer');
  const [text, setText] = useState('');
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  // Kept for the next request: one who chose to stay anonymous is not named by surprise.
  const [anonymous, setAnonymous] = useState(false);
  // Showing a prayer request to everyone needs a clear yes: never pre-ticked.
  const [share, setShare] = useState(false);
  const submit = useSubmit(() =>
    api('/submissions/prayer', { body: { channel, text, name: anonymous ? '' : name, place: anonymous ? '' : place, lang, consent_air: share } }),
  );
  // The community rules, once per device before the first post.
  const rulesNeeded = useRulesNeeded();
  const [rulesTicked, setRulesTicked] = useState(false);
  const close = (): void => {
    onClose();
    if (submit.done) {
      setText('');
      // The sheet stays mounted: the next request must ask again.
      setShare(false);
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
              if (rulesNeeded) acceptRules();
              void submit.run();
            }}
          >
            <div>
              <label className="label" htmlFor={`${uid}-text`}>{t('prayerForm.text')}</label>
              <textarea id={`${uid}-text`} className="field min-h-[120px]" maxLength={400} value={text} onChange={(e) => setText(e.target.value)} />
              <p className="mt-1 text-xs text-ink-faint">{t('prayerForm.textHint')}</p>
              {prayerHour && <p className="mt-1 text-xs text-ink-faint">{t('prayerForm.hourHint')}</p>}
            </div>
            <NameOrAnonymous
              anonymous={anonymous}
              setAnonymous={setAnonymous}
              label={t('prayerForm.anonymous')}
              hint={t('prayerForm.nameHint')}
              name={name}
              place={place}
              setName={setName}
              setPlace={setPlace}
            />
            <label className="flex items-start gap-2 text-sm text-ink-muted">
              <input type="checkbox" className="mt-1" checked={share} onChange={(e) => setShare(e.target.checked)} />
              {prayerHour ? t('prayerForm.showOnWallAfter') : t('prayerForm.showOnWall')}
            </label>
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            {rulesNeeded && <RulesCheckbox checked={rulesTicked} onChange={setRulesTicked} />}
            <PrivacyNote />
            <div className="flex gap-2">
              <button
                type="submit"
                className="btn-primary flex-1"
                disabled={text.trim().length < 5 || (!anonymous && name.trim() === '') || submit.busy || (rulesNeeded && !rulesTicked)}
              >
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
