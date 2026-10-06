import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { localDate } from '@/lib/format';
import { LINE_MOODS, LINE_TIMES, modError, type LineState, type LineTime, type ModLine } from './modApi';
import { ConfirmButton, Field, Pill } from './ui';

const LANGS: readonly Lang[] = ['en', 'de'];

const STATE_TONE: Record<LineState, 'default' | 'good' | 'bad' | 'warn'> = {
  draft: 'warn',
  recording: 'default',
  active: 'good',
  paused: 'default',
  failed: 'bad',
  removed: 'default',
};

let playing: HTMLAudioElement | null = null;

/** One clip at a time across every row: a second play button stops the first. */
function play(url: string): void {
  playing?.pause();
  const audio = new Audio(url);
  playing = audio;
  try {
    // A browser that refuses (no gesture, a format it lacks) just stays silent.
    void Promise.resolve(audio.play()).catch(() => undefined);
  } catch {
    /* refused outright */
  }
}

/**
 * One recorded line in /mod › Lines: its words and a play button per
 * language, when and how often it aired, and what a moderator may do with it.
 * Changed words are recorded again, which costs characters: that save asks
 * once more; a changed time or mood does not.
 */
export function LineRow({
  line,
  programTitle,
  selected,
  onSelect,
  onChanged,
}: {
  line: ModLine;
  /** The program it belongs to, or null for a line of every program. */
  programTitle: string | null;
  selected: boolean;
  onSelect: (on: boolean) => void;
  onChanged: (text: string, tone?: 'ok' | 'error') => void;
}) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);

  const run = async (call: () => Promise<unknown>, done: string): Promise<void> => {
    setBusy(true);
    try {
      await call();
      setEditing(false);
      onChanged(done);
    } catch (e) {
      onChanged(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const patch = (body: Record<string, unknown>, done: string): Promise<void> => run(() => api(`/mod/lines/${line.id}`, { method: 'PATCH', body }), done);
  const bulk = (action: 'approve' | 'rerecord', done: string): Promise<void> => run(() => api('/mod/lines/bulk', { body: { ids: [line.id], action } }), done);

  const removed = line.state === 'removed';
  return (
    <li className={clsx('card-inset flex flex-col gap-2 p-3', removed && 'opacity-60')}>
      <div className="flex flex-wrap items-center gap-2">
        <input
          type="checkbox"
          className="h-4 w-4 accent-accent-fill"
          checked={selected}
          disabled={removed}
          onChange={(e) => onSelect(e.target.checked)}
          aria-label={t('mod.lines.list.select', { id: line.id })}
        />
        <span className="text-sm font-medium">{t(`mod.lines.kinds.${line.kind}`)}</span>
        {programTitle !== null && <span className="text-xs text-ink-muted">· {programTitle}</span>}
        <Pill tone={STATE_TONE[line.state]}>{t(`mod.lines.states.${line.state}`)}</Pill>
        {line.old_voice && !removed && <Pill tone="warn">{t('mod.lines.oldVoice')}</Pill>}
        <span className="text-xs text-ink-faint">{t(`mod.lines.source.${line.source}`)}</span>
      </div>

      {editing ? (
        <LineEditor line={line} busy={busy} onSave={(body, recordAgain) => void patch(body, recordAgain ? t('mod.lines.done.rerecord') : t('mod.lines.done.saved'))} onCancel={() => setEditing(false)} />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
            {LANGS.map((l) => {
              const text = line.texts[l];
              if (!text) return null;
              const url = line.audio[l];
              const ms = line.durations[l];
              return (
                <div key={l} className="flex min-w-0 items-start gap-2">
                  {url ? (
                    <button
                      type="button"
                      className="btn-ghost shrink-0 px-2 py-1 text-xs"
                      onClick={() => play(url)}
                      aria-label={t('mod.lines.play', { lang: t(`mod.lines.langs.${l}`) })}
                    >
                      ▶ {l.toUpperCase()}
                    </button>
                  ) : (
                    <span className="shrink-0 px-2 py-1 text-xs text-ink-faint">{l.toUpperCase()}</span>
                  )}
                  <p className="min-w-0 break-words text-sm">
                    {text}
                    {ms ? <span className="ml-1 text-xs text-ink-faint">{(ms / 1000).toFixed(1)} s</span> : null}
                    {!url && <span className="ml-1 text-xs text-ink-faint">({t('mod.lines.notRecorded')})</span>}
                  </p>
                </div>
              );
            })}
          </div>
          {line.state === 'failed' && line.error && <p className="text-xs text-heart">{line.error}</p>}
          <p className="text-xs text-ink-muted">
            {[
              line.tags.time !== 'any' ? t(`mod.lines.times.${line.tags.time}`) : null,
              (LINE_MOODS as readonly string[]).includes(line.tags.mood) ? t(`mod.lines.moods.${line.tags.mood}`) : null,
              line.uses > 0 ? t('mod.lines.aired', { count: line.uses }) : t('mod.lines.neverAired'),
              line.last_aired !== null ? t('mod.lines.lastAired', { date: localDate(line.last_aired, lang) }) : null,
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
          {!removed && (
            <div className="flex flex-wrap items-center gap-2">
              <button type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => setEditing(true)}>
                {t('mod.common.edit')}
              </button>
              {line.state === 'active' && (
                <button type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => void patch({ state: 'paused' }, t('mod.lines.done.saved'))}>
                  {t('mod.lines.actions.pause')}
                </button>
              )}
              {line.state === 'paused' && (
                <button type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => void patch({ state: 'active' }, t('mod.lines.done.saved'))}>
                  {t('mod.lines.actions.resume')}
                </button>
              )}
              {line.state === 'draft' && (
                <button type="button" className="btn-ghost px-3 py-1.5 text-xs" disabled={busy} onClick={() => void bulk('approve', t('mod.lines.done.saved'))}>
                  {t('mod.lines.actions.approve')}
                </button>
              )}
              {(line.state === 'failed' || line.old_voice) && (
                <ConfirmButton
                  className="btn-ghost px-3 py-1.5 text-xs"
                  label={t('mod.lines.actions.rerecord')}
                  question={t('mod.lines.confirm.rerecord')}
                  disabled={busy}
                  onConfirm={() => void bulk('rerecord', t('mod.lines.done.rerecord'))}
                />
              )}
              <ConfirmButton
                className="btn-ghost px-3 py-1.5 text-xs text-heart"
                label={t('mod.lines.actions.remove')}
                question={t('mod.lines.confirm.remove')}
                disabled={busy}
                onConfirm={() => void run(() => api(`/mod/lines/${line.id}`, { method: 'DELETE' }), t('mod.lines.done.removed'))}
              />
            </div>
          )}
        </>
      )}
    </li>
  );
}

/** The words, time of day and mood of a line, edited in place. */
function LineEditor({
  line,
  busy,
  onSave,
  onCancel,
}: {
  line: ModLine;
  busy: boolean;
  onSave: (body: Record<string, unknown>, recordAgain: boolean) => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const [texts, setTexts] = useState<Record<Lang, string>>({ en: line.texts.en ?? '', de: line.texts.de ?? '' });
  const [time, setTime] = useState<LineTime>(line.tags.time);
  const [mood, setMood] = useState(line.tags.mood);
  const changed = LANGS.some((l) => texts[l].trim() !== (line.texts[l] ?? '').trim());
  const tags = { time, mood };
  // Only languages that had or now have words: an empty one stays out.
  const body = (): Record<string, unknown> => ({
    texts: Object.fromEntries(LANGS.filter((l) => texts[l].trim() !== '' || line.texts[l]).map((l) => [l, texts[l].trim()])),
    tags,
  });

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Field label={t('mod.lines.textEn')}>
          <textarea className="field min-h-[72px]" maxLength={1100} value={texts.en} onChange={(e) => setTexts((x) => ({ ...x, en: e.target.value }))} />
        </Field>
        <Field label={t('mod.lines.textDe')}>
          <textarea className="field min-h-[72px]" maxLength={1100} value={texts.de} onChange={(e) => setTexts((x) => ({ ...x, de: e.target.value }))} />
        </Field>
        <Field label={t('mod.lines.time')}>
          <select className="field" value={time} onChange={(e) => setTime(e.target.value as LineTime)}>
            {LINE_TIMES.map((x) => (
              <option key={x} value={x}>
                {t(`mod.lines.times.${x}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('mod.lines.mood')}>
          <select className="field" value={mood} onChange={(e) => setMood(e.target.value)}>
            <option value="">{t('mod.lines.moodNone')}</option>
            {LINE_MOODS.map((x) => (
              <option key={x} value={x}>
                {t(`mod.lines.moods.${x}`)}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {changed ? (
          <ConfirmButton className="btn-primary" label={t('mod.lines.edit.saveRecord')} question={t('mod.lines.confirm.saveRecord')} disabled={busy} onConfirm={() => onSave(body(), true)} />
        ) : (
          <button type="button" className="btn-primary" disabled={busy} onClick={() => onSave({ tags }, false)}>
            {t('mod.common.save')}
          </button>
        )}
        <button type="button" className="btn-ghost" onClick={onCancel}>
          {t('mod.common.cancel')}
        </button>
      </div>
    </div>
  );
}
