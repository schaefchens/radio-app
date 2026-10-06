import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { LINE_KINDS, modError, type LineKind, type LineOptions } from './modApi';
import { Check, Field, Notice, Section } from './ui';

const clamp = (v: string, min: number, max: number): number => {
  const n = Math.round(Number(v));
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : min;
};

/**
 * A host's library options (admins): whether it writes and records new lines
 * by itself, whether they need an approval, how many lines of each kind it
 * keeps, how soon a line may come back, and how many characters it may record
 * a month — 0 records nothing, the way an ElevenLabs host without a daily cap
 * never speaks. Keyed by host by its parent: another host's options start a
 * fresh form.
 */
export function LineOptionsCard({ hostId, options, onSaved }: { hostId: number; options: LineOptions; onSaved: () => void }) {
  const { t, i18n } = useTranslation();
  const [d, setD] = useState<LineOptions>(options);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const set = <K extends keyof LineOptions>(k: K, v: LineOptions[K]): void => setD((x) => ({ ...x, [k]: v }));
  const setTarget = (k: LineKind, v: number): void => setD((x) => ({ ...x, targets: { ...x.targets, [k]: v } }));

  const save = async (): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      const r = await api<{ options: LineOptions }>(`/mod/hosts/${hostId}/lines`, { method: 'PATCH', body: d });
      // What the server kept (it clamps), so the form shows what applies.
      setD(r.options);
      setNotice({ tone: 'ok', text: t('mod.lines.options.saved') });
      onSaved();
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title={t('mod.lines.options.title')}>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <div className="flex flex-col gap-2">
        <Check label={t('mod.lines.options.refill')} checked={d.refill} onChange={(v) => set('refill', v)} />
        <Check label={t('mod.lines.options.live')} checked={d.live} onChange={(v) => set('live', v)} />
        <Check label={t('mod.lines.options.oldVoice')} checked={d.old_voice} onChange={(v) => set('old_voice', v)} />
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label={t('mod.lines.options.monthChars')} hint={t('mod.lines.options.monthHint', { example: (75_000).toLocaleString(i18n.language) })}>
          <input type="number" min={0} max={10_000_000} step={1000} className="field" value={d.month_chars} onChange={(e) => set('month_chars', clamp(e.target.value, 0, 10_000_000))} />
        </Field>
        <Field label={t('mod.lines.options.restHours')} hint={t('mod.lines.options.restHint')}>
          <input type="number" min={1} max={720} className="field" value={d.rest_hours} onChange={(e) => set('rest_hours', clamp(e.target.value, 1, 720))} />
        </Field>
      </div>
      <div className="flex flex-col gap-2">
        <p className="label">{t('mod.lines.options.targets')}</p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {LINE_KINDS.map((k) => (
            <Field key={k} label={t(`mod.lines.kinds.${k}`)}>
              <input type="number" min={0} max={200} className="field" value={d.targets[k] ?? 0} onChange={(e) => setTarget(k, clamp(e.target.value, 0, 200))} />
            </Field>
          ))}
        </div>
      </div>
      <div>
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
          {busy ? t('mod.common.saving') : t('mod.common.save')}
        </button>
      </div>
    </Section>
  );
}
