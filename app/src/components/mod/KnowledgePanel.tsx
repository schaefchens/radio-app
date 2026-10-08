import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useSession } from '@/store/session';
import { useApi } from './useApi';
import { modError, type KnowledgeFact, type KnowledgeRecord, type KnowledgeSettings, type KnowledgeSummary, type LibraryItem } from './modApi';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';

/**
 * What the station knows about its songs and videos (server: Library\Knowledge):
 * each looked up once — OpenAI's web search for who and what it is, Gemini
 * listening for what is sung or said, judged against the station's standard.
 * Moderators read and correct it per item; admins decide whether the checks
 * and the host use it, the day's budget and the standard.
 */

interface SettingsAnswer {
  settings: KnowledgeSettings;
  standardDefault: string;
  spentMicros: number;
  configured: { research: boolean; listen: boolean };
  counts: { state: string; n: number }[];
}

/** An item's knowledge in one pill: the worst first — not biblical, not Christian, explicit, a concern. */
export function KnowledgePill({ k }: { k: KnowledgeSummary }) {
  const { t } = useTranslation();
  if (k.state !== 'ready') return <Pill tone={k.state === 'failed' ? 'bad' : 'default'}>{t(`mod.knowledge.state.${k.state}`)}</Pill>;
  if (k.biblical === 'no') return <Pill tone="bad">{t('mod.knowledge.biblical.no')}</Pill>;
  if (k.christian === 'no') return <Pill tone="bad">{t('mod.knowledge.christian.no')}</Pill>;
  if (k.explicit) return <Pill tone="bad">{t('mod.knowledge.explicit')}</Pill>;
  if (k.concern) return <Pill tone="warn">{t('mod.knowledge.biblical.concern')}</Pill>;
  return <Pill tone="good">{t('mod.knowledge.state.ready')}</Pill>;
}

/** The admins' switches, the day's budget and the station's standard. */
export function KnowledgeSettingsSection() {
  const { t } = useTranslation();
  const admin = useSession((s) => s.identity?.role === 'admin');
  const { data, error, reload } = useApi<SettingsAnswer>(admin ? '/mod/knowledge' : null);
  if (!admin) return null;
  return (
    <Section title={t('mod.knowledge.title')}>
      <p className="text-sm text-ink-muted">{t('mod.knowledge.intro')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      {!data ? <Loading /> : <SettingsForm key={JSON.stringify(data.settings)} data={data} onSaved={reload} />}
    </Section>
  );
}

function SettingsForm({ data, onSaved }: { data: SettingsAnswer; onSaved: () => void }) {
  const { t } = useTranslation();
  const [checks, setChecks] = useState(data.settings.checks);
  const [air, setAir] = useState(data.settings.air);
  const [budget, setBudget] = useState(String(data.settings.budget_usd));
  const [standard, setStandard] = useState(data.settings.standard);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const count = (state: string): number => data.counts.find((c) => c.state === state)?.n ?? 0;

  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      await api('/mod/knowledge', { method: 'PUT', body: { checks, air, budget_usd: Number(budget) || 0, standard: standard.trim() === data.standardDefault.trim() ? '' : standard } });
      setNotice({ tone: 'ok', text: t('mod.common.saved') });
      onSaved();
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-3">
      {!data.configured.listen && <Notice tone="error">{t('mod.knowledge.notConfigured')}</Notice>}
      <p className="text-xs text-ink-muted">
        {t('mod.knowledge.counts', { ready: count('ready'), working: count('working') + count('queued'), failed: count('failed') })} ·{' '}
        {t('mod.knowledge.spent', { amount: (data.spentMicros / 1e6).toFixed(2) })}
      </p>
      <div className="flex flex-col gap-1">
        <Check label={t('mod.knowledge.checks')} checked={checks} onChange={setChecks} disabled={!data.configured.listen && !checks} />
        <p className="pl-6 text-xs text-ink-muted">{t('mod.knowledge.checksHint')}</p>
        <Check label={t('mod.knowledge.air')} checked={air} onChange={setAir} />
        <p className="pl-6 text-xs text-ink-muted">{t('mod.knowledge.airHint')}</p>
      </div>
      <Field label={t('mod.knowledge.budget')} hint={Number(budget) > 0 ? undefined : t('mod.knowledge.budgetZero')}>
        <input className="field w-32" type="number" min={0} max={100} step={0.5} value={budget} onChange={(e) => setBudget(e.target.value)} />
      </Field>
      <Field label={t('mod.knowledge.standard')} hint={t('mod.knowledge.standardHint')}>
        <textarea className="field min-h-40" maxLength={3000} value={standard} onChange={(e) => setStandard(e.target.value)} />
      </Field>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <div>
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
          {t('mod.common.save')}
        </button>
      </div>
      <Transfer onImported={onSaved} />
    </div>
  );
}

/** What GET /mod/knowledge/export answers (server: Library\Knowledge::export), as far as this page reads it. */
interface KnowledgeFile {
  exported: number;
  rows: unknown[];
}

/**
 * Another station's look-ups, without paying twice: downloaded on one
 * (the local stack), uploaded on the other (the live one), which takes what
 * it does not know yet — checked again on the way in.
 */
function Transfer({ onImported }: { onImported: () => void }) {
  const { t } = useTranslation();
  const [replace, setReplace] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  const download = async (): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      const file = await api<KnowledgeFile>('/mod/knowledge/export');
      const name = `arche-knowledge-${new Date(file.exported * 1000).toISOString().slice(0, 10).replaceAll('-', '')}.json`;
      const url = URL.createObjectURL(new Blob([JSON.stringify(file)], { type: 'application/json' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = name;
      document.body.append(link);
      link.click();
      link.remove();
      // Revoked at once, some browsers would drop the download before it starts.
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
      setNotice({ tone: 'ok', text: t('mod.knowledge.downloaded', { name, n: file.rows.length }) });
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  const upload = async (file: File): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      const form = new FormData();
      form.set('file', file);
      form.set('replace', replace ? '1' : '0');
      const r = await api<{ taken: number; kept: number; refused: number }>('/mod/knowledge/import', { form });
      setNotice({ tone: 'ok', text: t('mod.knowledge.imported', r) });
      onImported();
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-2 border-t border-line/20 pt-3">
      <p className="font-medium">{t('mod.knowledge.transfer')}</p>
      <p className="text-xs text-ink-muted">{t('mod.knowledge.transferHint')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-ghost" disabled={busy} onClick={() => void download()}>
          {t('mod.knowledge.download')}
        </button>
        <label className="btn-ghost cursor-pointer">
          {t('mod.knowledge.upload')}
          <input
            type="file"
            accept="application/json,.json"
            className="sr-only"
            disabled={busy}
            onChange={(e) => {
              const f = e.target.files?.[0];
              e.target.value = '';
              if (f) void upload(f);
            }}
          />
        </label>
        <Check label={t('mod.knowledge.replace')} checked={replace} onChange={setReplace} />
      </div>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </div>
  );
}

/** One item's knowledge: read, corrected, looked up again. */
export function KnowledgeEditor({ item, onChanged }: { item: LibraryItem; onChanged: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ item: LibraryItem; knowledge: KnowledgeRecord | null }>(`/mod/library/${item.id}/knowledge`);
  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;
  const k = data.knowledge;
  const again = async (): Promise<void> => {
    try {
      await api(`/mod/library/${item.id}/knowledge/again`, { body: {} });
      onChanged(t('mod.knowledge.againStarted'));
      reload();
    } catch (e) {
      onChanged(modError(e), 'error');
    }
  };
  return (
    <div className="flex flex-col gap-3 border-t border-line/20 pt-3">
      {!k || k.state === 'failed' ? (
        <p className="text-sm text-ink-muted">{k ? t('mod.knowledge.error', { error: k.error || '—' }) : t('mod.knowledge.notLookedUp')}</p>
      ) : k.state !== 'ready' ? (
        <p className="text-sm text-ink-muted">{t('mod.knowledge.state.working')}</p>
      ) : (
        <ReadyRecord
          key={`${k.researched}:${k.edited_by}`}
          item={data.item}
          k={k}
          onSaved={(text, tone) => {
            onChanged(text, tone);
            reload();
          }}
        />
      )}
      <div>
        <ConfirmButton className="btn-ghost px-3 py-1.5 text-xs" label={t('mod.knowledge.again')} question={t('mod.knowledge.againConfirm')} onConfirm={() => void again()} />
      </div>
    </div>
  );
}

function ReadyRecord({ item, k, onSaved }: { item: LibraryItem; k: KnowledgeRecord; onSaved: (text: string, tone?: 'ok' | 'error') => void }) {
  const { t, i18n } = useTranslation();
  const de = i18n.language === 'de';
  const a = k.analysis;
  const id = k.research.identity;
  const [message, setMessage] = useState({ en: a.message_en ?? '', de: a.message_de ?? '' });
  const [bible, setBible] = useState((a.bible_refs ?? []).join('\n'));
  const [facts, setFacts] = useState<KnowledgeFact[]>(k.research.facts ?? []);
  const [text, setText] = useState(k.text);
  const [busy, setBusy] = useState(false);
  const pd = k.research.public_domain?.is ?? false;
  const namesDiffer = k.title !== '' && (k.title !== item.title || (k.artist !== '' && k.artist !== item.artist));

  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      const body: Record<string, unknown> = { about: message, bible: bible.split('\n').map((b) => b.trim()).filter(Boolean), facts };
      if (pd) body.text = text;
      await api(`/mod/library/${item.id}/knowledge`, { method: 'PATCH', body });
      onSaved(t('mod.common.saved'));
    } catch (e) {
      onSaved(modError(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const takeNames = async (): Promise<void> => {
    try {
      await api(`/mod/library/${item.id}/knowledge/names`, { body: {} });
      onSaved(t('mod.common.saved'));
    } catch (e) {
      onSaved(modError(e), 'error');
    }
  };
  const setFact = (i: number, patch: Partial<KnowledgeFact>): void => setFacts((fs) => fs.map((f, j) => (j === i ? { ...f, ...patch } : f)));

  return (
    <div className="flex flex-col gap-4 text-sm">
      <div className="flex flex-wrap gap-1">
        <Pill tone={a.christian === 'yes' ? 'good' : a.christian === 'no' ? 'bad' : 'warn'}>{t(`mod.knowledge.christian.${a.christian ?? 'unclear'}`)}</Pill>
        <Pill tone={a.biblical === 'yes' ? 'good' : a.biblical === 'no' ? 'bad' : 'warn'}>{t(`mod.knowledge.biblical.${a.biblical ?? 'concern'}`)}</Pill>
        {a.explicit && <Pill tone="bad">{t('mod.knowledge.explicit')}</Pill>}
        {a.energy && <Pill>{a.energy}</Pill>}
        {a.age && <Pill>{t('mod.knowledge.age', { age: a.age })}</Pill>}
        {(a.fits ?? []).map((f) => (
          <Pill key={f}>{f}</Pill>
        ))}
      </div>

      <div>
        <p className="font-medium">{t('mod.knowledge.names')}</p>
        <p className="text-xs text-ink-muted">{t('mod.knowledge.youtubeNames', { title: k.yt_title, artist: k.yt_artist })}</p>
        {k.title !== '' && <p className="text-xs">{t('mod.knowledge.suggested', { title: k.title, artist: k.artist })}</p>}
        {namesDiffer && (
          <button type="button" className="btn-ghost mt-1 px-3 py-1 text-xs" onClick={() => void takeNames()}>
            {t('mod.knowledge.useNames')}
          </button>
        )}
      </div>

      {id && (id.original || id.writers.length > 0 || id.artist_background) && (
        <div className="flex flex-col gap-0.5">
          <p className="font-medium">{t('mod.knowledge.identity')}</p>
          {id.original && <p className="text-xs">{t('mod.knowledge.original', { original: id.original })}</p>}
          {id.writers.length > 0 && (
            <p className="text-xs">{t('mod.knowledge.writers', { writers: id.writers.map((w) => w.name + (w.died ? ` († ${w.died})` : '')).join(', ') })}</p>
          )}
          {id.artist_background && <p className="text-xs text-ink-muted">{id.artist_background}</p>}
        </div>
      )}

      <div className="flex flex-col gap-1">
        <p className="font-medium">{t('mod.knowledge.heard')}</p>
        <p className="text-xs">{(de ? a.summary_de : a.summary_en) || a.summary_en}</p>
        {a.addressed_to && <p className="text-xs text-ink-muted">{t('mod.knowledge.addressedTo', { who: a.addressed_to })}</p>}
        {a.christian_why && <p className="text-xs text-ink-muted">{a.christian_why}</p>}
        {(a.quotes ?? []).length > 0 && (
          <ul className="list-disc pl-5 text-xs text-ink-muted">
            {(a.quotes ?? []).map((q, i) => (
              <li key={i}>
                „{q.text}“{q.at ? ` (${q.at})` : ''}
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="flex flex-col gap-1">
        <p className="font-medium">{t('mod.knowledge.concerns')}</p>
        {(a.concerns ?? []).length === 0 ? (
          <p className="text-xs text-ink-muted">{t('mod.knowledge.noConcerns')}</p>
        ) : (
          <ul className="flex flex-col gap-1 text-xs">
            {(a.concerns ?? []).map((c, i) => (
              <li key={i} className="rounded-lg bg-warn/10 px-2 py-1">
                <span className="font-medium">{c.what}</span> — {c.why}
                {c.quote && <span className="text-ink-muted"> („{c.quote}“)</span>}
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('mod.knowledge.messageEn')} hint={t('mod.knowledge.messageHint')}>
          <textarea className="field min-h-16" maxLength={300} value={message.en} onChange={(e) => setMessage((m) => ({ ...m, en: e.target.value }))} />
        </Field>
        <Field label={t('mod.knowledge.messageDe')}>
          <textarea className="field min-h-16" maxLength={300} value={message.de} onChange={(e) => setMessage((m) => ({ ...m, de: e.target.value }))} />
        </Field>
      </div>
      <Field label={t('mod.knowledge.bible')}>
        <textarea className="field min-h-12" value={bible} onChange={(e) => setBible(e.target.value)} />
      </Field>

      <div className="flex flex-col gap-2">
        <p className="font-medium">{t('mod.knowledge.facts')}</p>
        {facts.map((f, i) => (
          <div key={i} className="card-inset flex flex-col gap-2 p-2">
            <input className="field" placeholder={t('mod.knowledge.factEn')} aria-label={t('mod.knowledge.factEn')} value={f.en} onChange={(e) => setFact(i, { en: e.target.value })} />
            <input className="field" placeholder={t('mod.knowledge.factDe')} aria-label={t('mod.knowledge.factDe')} value={f.de} onChange={(e) => setFact(i, { de: e.target.value })} />
            <div className="flex flex-wrap items-center gap-2">
              <input className="field flex-1" placeholder={t('mod.knowledge.source')} aria-label={t('mod.knowledge.source')} value={f.source} onChange={(e) => setFact(i, { source: e.target.value })} />
              {f.source.startsWith('https://') && (
                <a className="text-xs underline" href={f.source} target="_blank" rel="noopener noreferrer">
                  {t('mod.knowledge.openSource')}
                </a>
              )}
              {k.facts_told[String(i)] ? <span className="text-xs text-ink-muted">{t('mod.knowledge.told', { when: new Date(k.facts_told[String(i)]!).toLocaleDateString() })}</span> : null}
              <button type="button" className="btn-ghost px-2 py-1 text-xs text-heart" onClick={() => setFacts((fs) => fs.filter((_, j) => j !== i))}>
                {t('mod.knowledge.removeFact')}
              </button>
            </div>
          </div>
        ))}
        {facts.length < 5 && (
          <div>
            <button type="button" className="btn-ghost px-3 py-1 text-xs" onClick={() => setFacts((fs) => [...fs, { en: '', de: '', source: '' }])}>
              {t('mod.knowledge.addFact')}
            </button>
          </div>
        )}
      </div>

      {pd && (
        <Field label={t('mod.knowledge.publicDomain')} hint={t('mod.knowledge.publicDomainHint')}>
          <textarea className="field min-h-24" value={text} onChange={(e) => setText(e.target.value)} />
        </Field>
      )}

      <p className="text-xs text-ink-faint">
        {t('mod.knowledge.researched', { when: new Date(k.researched * 1000).toLocaleDateString(), cost: (k.cost_micros / 1e6).toFixed(2) })}
        {k.edited_by ? ` · ${t('mod.knowledge.editedBy', { who: k.edited_by })}` : ''}
        {k.error ? ` · ${t('mod.knowledge.error', { error: k.error })}` : ''}
      </p>
      <div>
        <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
          {t('mod.common.save')}
        </button>
      </div>
    </div>
  );
}
