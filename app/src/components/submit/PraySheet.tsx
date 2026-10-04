import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { toMp3 } from '@/lib/recordingEncoder';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { useRecorder } from './useRecorder';
import { RecorderPanel } from './RecordSheet';
import { Done, NameOrAnonymous, PrivacyNote } from './VideoRequestSheet';
import { RulesCheckbox } from '@/components/common/RulesConsent';
import { acceptRules, useRulesNeeded } from '@/lib/rulesConsent';

/** A spoken prayer, as long as the station takes one (PRAYER_MAX_SECONDS). */
const LIMIT_S = 60;

/**
 * A listener's own prayer in a prayer hour's prayer time, for the requests
 * read out: spoken first (the recorder — played on air as it is) or written
 * ("Type it instead" — read out word for word in the host's voice). With
 * first name and place unless the sender stays anonymous, and the yes to air
 * it, never ticked in advance; the community rules before the first post;
 * the Art. 9 note above Send.
 */
export function PraySheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const uid = useId();
  const identity = useSession((s) => s.identity);
  const lang = useSettings((s) => s.lang);
  const channel = useRadio((s) => s.engine.channel);
  const [written, setWritten] = useState(false);
  const [text, setText] = useState('');
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  // Kept for the next prayer: one who chose to stay anonymous is not named by surprise.
  const [anonymous, setAnonymous] = useState(false);
  const [consent, setConsent] = useState(false);
  const rulesNeeded = useRulesNeeded();
  const [rulesTicked, setRulesTicked] = useState(false);
  const rec = useRecorder();

  const submit = useSubmit(async () => {
    const who = anonymous ? { name: '', place: '' } : { name, place };
    if (written) {
      await api('/submissions/intercession', { body: { channel, text, ...who, lang, consent_air: consent } });
      return;
    }
    if (!rec.blob) throw new Error('no recording');
    const { mp3 } = await toMp3(rec.blob);
    const form = new FormData();
    form.set('channel', channel);
    form.set('type', 'intercession');
    form.set('name', who.name);
    form.set('place', who.place);
    form.set('lang', lang);
    form.set('consent_air', consent ? '1' : '');
    form.set('audio', mp3, 'prayer.mp3');
    await api('/submissions/audio', { form });
  });

  const close = (): void => {
    if (rec.recording) rec.stop();
    rec.cleanup();
    onClose();
    if (submit.done) {
      setText('');
      rec.reset();
      // The sheet stays mounted: the next prayer must be agreed to again.
      setConsent(false);
      submit.reset();
    }
  };

  const ready = (written ? text.trim().length >= 5 : rec.blob !== null && !rec.recording) && (anonymous || name.trim() !== '');
  return (
    <BottomSheet open={open} onClose={close} title={t('pray.title')}>
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
            <p className="text-sm text-ink-muted">{written ? t('pray.introWritten') : t('pray.introSpoken')}</p>
            {written ? (
              <div>
                <label className="label" htmlFor={`${uid}-text`}>{t('pray.text')}</label>
                <textarea id={`${uid}-text`} className="field min-h-[120px]" maxLength={400} value={text} onChange={(e) => setText(e.target.value)} />
              </div>
            ) : (
              <RecorderPanel rec={rec} limitS={LIMIT_S} />
            )}
            <button
              type="button"
              className="btn-ghost self-start"
              disabled={rec.recording}
              onClick={() => setWritten(!written)}
            >
              {written ? t('prayerForm.record') : t('prayerForm.type')}
            </button>
            <NameOrAnonymous
              anonymous={anonymous}
              setAnonymous={setAnonymous}
              label={t('submit.anonymous')}
              hint={t('pray.nameHint')}
              name={name}
              place={place}
              setName={setName}
              setPlace={setPlace}
            />
            <label className="flex items-start gap-2 text-sm text-ink-muted">
              <input type="checkbox" className="mt-1" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
              {t('pray.consent')}
            </label>
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            {rulesNeeded && <RulesCheckbox checked={rulesTicked} onChange={setRulesTicked} />}
            <PrivacyNote />
            <button type="submit" className="btn-primary" disabled={!ready || !consent || submit.busy || (rulesNeeded && !rulesTicked)}>
              {submit.busy ? (written ? t('common.loading') : t('record.preparing')) : t('submit.send')}
            </button>
          </form>
        )}
      </BottomSheetBody>
    </BottomSheet>
  );
}
