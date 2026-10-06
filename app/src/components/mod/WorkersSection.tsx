import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { ago } from '@/lib/format';
import { useApi } from './useApi';
import { modError, type ModWorker } from './modApi';
import { ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';
import { configSnippet } from './workerConfig';

const NO_WORKERS: ModWorker[] = [];
const wallClock = (): number => Date.now();

/**
 * /mod › Hosts › Computers (admins): the station's own Macs that speak for
 * the hosts with Qwen. Each fetches its jobs from the station by itself (it
 * has no public address) with a key of its own: shown once when it is added
 * or renewed, stored only as a fingerprint. Whether it is online, what it
 * offers, what it did today; renamed, switched off, given a new key, removed.
 */
export function WorkersSection() {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ workers: ModWorker[] }>('/mod/workers');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  // The key of a computer just added or renewed: this is the only time anyone sees it.
  const [shown, setShown] = useState<{ name: string; key: string } | null>(null);
  const workers = data?.workers ?? NO_WORKERS;

  const add = async (): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      const r = await api<{ worker: ModWorker; key: string }>('/mod/workers', { body: { name: name.trim() } });
      setShown({ name: r.worker.name, key: r.key });
      setName('');
      reload();
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  const changed = (text: string, key?: { name: string; key: string }): void => {
    setNotice({ tone: 'ok', text });
    if (key) setShown(key);
    reload();
  };

  return (
    <Section title={t('mod.workers.title')}>
      <p className="text-sm text-ink-muted">{t('mod.workers.intro')}</p>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {shown && <KeyBox name={shown.name} keyValue={shown.key} onClose={() => setShown(null)} />}
      {error ? (
        <Notice tone="error">{error}</Notice>
      ) : !data ? (
        <Loading />
      ) : workers.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.workers.empty')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {workers.map((w) => (
            <WorkerRow key={`${w.id}:${w.name}:${String(w.active)}`} worker={w} onChanged={changed} />
          ))}
        </ul>
      )}
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          void add();
        }}
      >
        <div className="min-w-0 flex-1">
          <Field label={t('mod.workers.name')}>
            <input className="field" maxLength={60} value={name} placeholder={t('mod.workers.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
          </Field>
        </div>
        <button type="submit" className="btn-primary" disabled={busy || name.trim() === ''}>
          {t('mod.workers.add')}
        </button>
      </form>
    </Section>
  );
}

/** One computer: whether it is online, what it offers and did today, and what an admin may do with it. */
function WorkerRow({ worker: w, onChanged }: { worker: ModWorker; onChanged: (text: string, key?: { name: string; key: string }) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(w.name);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const patch = async (body: Record<string, unknown>, text: string): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ worker: ModWorker; key?: string }>(`/mod/workers/${w.id}`, { method: 'PATCH', body });
      onChanged(text, r.key ? { name: r.worker.name, key: r.key } : undefined);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await api(`/mod/workers/${w.id}`, { method: 'DELETE' });
      onChanged(t('mod.workers.deleted'));
    } catch (e) {
      setError(modError(e));
      setBusy(false);
    }
  };

  const voices = w.voices.map((v) => v.label || v.id).join(', ');
  return (
    <li className="card-inset flex flex-col gap-2 p-3">
      <div className="flex flex-wrap items-center gap-2">
        {renaming ? (
          <input className="field w-auto min-w-0 flex-1" maxLength={60} value={name} aria-label={t('mod.workers.name')} onChange={(e) => setName(e.target.value)} />
        ) : (
          <p className="min-w-0 truncate font-medium">{w.name}</p>
        )}
        {!w.active ? <Pill>{t('mod.common.inactive')}</Pill> : w.online ? <Pill tone="good">{t('mod.workers.online')}</Pill> : <Pill tone="warn">{t('mod.workers.offline')}</Pill>}
        <span className="text-xs text-ink-muted">{w.last_seen > 0 ? t('mod.workers.lastSeen', { when: ago(w.last_seen * 1000, wallClock(), lang) }) : t('mod.workers.never')}</span>
      </div>
      {(w.version || w.engine.model || voices) && (
        <p className="break-words text-xs text-ink-muted">{[w.version, w.engine.model, voices && t('mod.workers.voices', { list: voices })].filter(Boolean).join(' · ')}</p>
      )}
      <p className="text-xs text-ink-muted">
        {t('mod.workers.tasks', { done: w.tasks.done_today, failed: w.tasks.failed_today, queued: w.tasks.queued })}
        {w.key_hint && ` · ${t('mod.workers.keyHint', { hint: w.key_hint })}`}
      </p>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="flex flex-wrap items-center gap-2">
        {renaming ? (
          <>
            <button type="button" className="btn-primary px-3 py-1.5 text-sm" disabled={busy || name.trim() === ''} onClick={() => void patch({ name: name.trim() }, t('mod.workers.saved'))}>
              {t('mod.common.save')}
            </button>
            <button
              type="button"
              className="btn-ghost px-3 py-1.5 text-sm"
              onClick={() => {
                setRenaming(false);
                setName(w.name);
              }}
            >
              {t('mod.common.cancel')}
            </button>
          </>
        ) : (
          <button type="button" className="btn-ghost px-3 py-1.5 text-sm" onClick={() => setRenaming(true)}>
            {t('mod.workers.rename')}
          </button>
        )}
        <button type="button" className="btn-ghost px-3 py-1.5 text-sm" disabled={busy} onClick={() => void patch({ active: !w.active }, t('mod.workers.saved'))}>
          {w.active ? t('mod.workers.deactivate') : t('mod.workers.activate')}
        </button>
        <ConfirmButton
          className="btn-ghost px-3 py-1.5 text-sm"
          label={t('mod.workers.newKey')}
          question={t('mod.workers.newKeyConfirm', { name: w.name })}
          disabled={busy}
          onConfirm={() => void patch({ rotate: true }, t('mod.workers.keyRenewed'))}
        />
        <ConfirmButton
          className="btn-ghost px-3 py-1.5 text-sm"
          label={t('mod.common.delete')}
          question={t('mod.workers.deleteConfirm', { name: w.name })}
          disabled={busy}
          onConfirm={() => void remove()}
        />
      </div>
    </li>
  );
}

/** The key, shown this once, and the lines that go into the computer's config. */
function KeyBox({ name, keyValue, onClose }: { name: string; keyValue: string; onClose: () => void }) {
  const { t } = useTranslation();
  const snippet = configSnippet(keyValue, window.location.origin);
  const [copied, setCopied] = useState(false);
  const copy = async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied(true);
    } catch {
      // No clipboard here: the text stays selectable.
      setCopied(false);
    }
  };
  return (
    <div className="card-inset flex flex-col gap-2 border border-accent/40 p-3" role="status">
      <p className="label">{t('mod.workers.keyTitle', { name })}</p>
      <p className="text-sm">{t('mod.workers.keyOnce')}</p>
      <pre className="overflow-x-auto rounded-lg bg-soft p-2 text-xs" aria-label={t('mod.workers.snippet')}>
        <code>{snippet}</code>
      </pre>
      <p className="text-xs text-ink-faint">{t('mod.workers.snippetHint')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-primary px-3 py-1.5 text-sm" onClick={() => void copy()}>
          {copied ? t('mod.workers.copied') : t('mod.workers.copy')}
        </button>
        <button type="button" className="btn-ghost px-3 py-1.5 text-sm" onClick={onClose}>
          {t('mod.workers.keyDone')}
        </button>
      </div>
    </div>
  );
}
