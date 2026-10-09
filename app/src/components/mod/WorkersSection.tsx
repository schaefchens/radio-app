import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { api } from '@/lib/api';
import { ago, localDate, localTime } from '@/lib/format';
import { useApi } from './useApi';
import { modError, type ModWorker, type ModWorkerInvite } from './modApi';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';
import { configSnippet, joinCommand } from './workerConfig';

const NO_WORKERS: ModWorker[] = [];
const NO_INVITES: ModWorkerInvite[] = [];
const wallClock = (): number => Date.now();

/**
 * /mod › Hosts › Computers (admins): the computers that speak for the hosts
 * with Qwen — ours, and ones lent by people we trust. Each fetches its work
 * from the station by itself (no public address needed) with a key of its
 * own. A computer joins with a one-time invite (herde trades the code for
 * its key); a lent one gets work without listeners' words only, and moments
 * on air only when an admin lets it. The old voice worker (protocol 1) is
 * still added with a key shown once.
 */
export function WorkersSection() {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ workers: ModWorker[]; invites?: ModWorkerInvite[] }>('/mod/workers');
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  // A key or an invite's code just made: this is the only time anyone sees it.
  const [shown, setShown] = useState<{ name: string; key: string } | null>(null);
  const [invited, setInvited] = useState<{ name: string; code: string; expires: number } | null>(null);
  const workers = data?.workers ?? NO_WORKERS;
  const invites = data?.invites ?? NO_INVITES;

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
      {invited && <InviteBox {...invited} onClose={() => setInvited(null)} />}
      {error ? (
        <Notice tone="error">{error}</Notice>
      ) : !data ? (
        <Loading />
      ) : workers.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.workers.empty')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {workers.map((w) => (
            <WorkerRow key={`${w.id}:${w.name}:${String(w.active)}:${String(w.live)}`} worker={w} onChanged={changed} />
          ))}
        </ul>
      )}
      {invites.length > 0 && <OpenInvites invites={invites} onChanged={changed} />}
      <InviteForm
        onInvited={(name, code, expires) => {
          setNotice(null);
          setInvited({ name, code, expires });
          reload();
        }}
        onError={(text) => setNotice({ tone: 'error', text })}
      />
      <details className="text-sm">
        <summary className="cursor-pointer text-ink-muted">{t('mod.workers.keyedTitle')}</summary>
        <KeyedForm
          onAdded={(name, key) => {
            setShown({ name, key });
            reload();
          }}
          onError={(text) => setNotice({ tone: 'error', text })}
        />
      </details>
    </Section>
  );
}

/** One computer: whose it is, whether it can work now, what it offers and did today, and what an admin may do with it. */
function WorkerRow({ worker: w, onChanged }: { worker: ModWorker; onChanged: (text: string, key?: { name: string; key: string }) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(w.name);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const now = wallClock();

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

  const resting = (w.resting_until ?? 0) * 1000 > now;
  const status = !w.active ? (
    <Pill>{t('mod.common.inactive')}</Pill>
  ) : resting ? (
    <Pill tone="bad">{t('mod.workers.resting', { when: localTime((w.resting_until ?? 0) * 1000, lang) })}</Pill>
  ) : w.state === 'paused' ? (
    <Pill tone="warn">{w.resume_at ? t('mod.workers.pausedUntil', { when: localTime(w.resume_at * 1000, lang) }) : t('mod.workers.paused')}</Pill>
  ) : w.state === 'loading' ? (
    <Pill tone="warn">{t('mod.workers.loading')}</Pill>
  ) : w.online ? (
    <Pill tone="good">{t('mod.workers.online')}</Pill>
  ) : (
    <Pill tone="warn">{t('mod.workers.offline')}</Pill>
  );
  const lender = w.trust === 'lender';
  const voices = w.voices.map((v) => v.label || v.id).join(', ');
  const engines = w.engines
    ? w.engines.map((e) => t(e.kind === 'text' ? 'mod.workers.textEngine' : 'mod.workers.speechEngine', { model: e.model })).join(', ')
    : w.engine.model;
  const platform = [w.platform?.accelerator || [w.platform?.os, w.platform?.arch].filter(Boolean).join(' '), w.protocol === 1 ? t('mod.workers.oldWorker') : '']
    .filter(Boolean)
    .join(', ');
  return (
    <li className="card-inset flex flex-col gap-2 p-3">
      <div className="flex flex-wrap items-center gap-2">
        {renaming ? (
          <input className="field w-auto min-w-0 flex-1" maxLength={60} value={name} aria-label={t('mod.workers.name')} onChange={(e) => setName(e.target.value)} />
        ) : (
          <p className="min-w-0 truncate font-medium">{w.name}</p>
        )}
        {w.trust && <Pill tone={lender ? 'warn' : 'default'}>{lender ? t('mod.workers.trustLender') : t('mod.workers.trustOwn')}</Pill>}
        {status}
        <span className="text-xs text-ink-muted">{w.last_seen > 0 ? t('mod.workers.lastSeen', { when: ago(w.last_seen * 1000, now, lang) }) : t('mod.workers.never')}</span>
      </div>
      {(w.version || engines || voices || platform) && (
        <p className="break-words text-xs text-ink-muted">
          {[w.version, platform, engines, voices && t('mod.workers.voices', { list: voices })].filter(Boolean).join(' · ')}
        </p>
      )}
      <p className="text-xs text-ink-muted">
        {t('mod.workers.tasks', { done: w.tasks.done_today, failed: w.tasks.failed_today, queued: w.tasks.queued })}
        {w.key_hint && ` · ${t('mod.workers.keyHint', { hint: w.key_hint })}`}
      </p>
      {lender && (
        <div className="flex flex-col gap-1">
          <Check label={t('mod.workers.live')} checked={w.live === true} disabled={busy} onChange={(live) => void patch({ live }, t('mod.workers.saved'))} />
          <p className="text-xs text-ink-faint">{t('mod.workers.liveHint')}</p>
        </div>
      )}
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

/** Invites nobody has used yet, each with how long it holds; withdrawn with a click. */
function OpenInvites({ invites, onChanged }: { invites: ModWorkerInvite[]; onChanged: (text: string) => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const [error, setError] = useState<string | null>(null);
  const withdraw = async (id: number): Promise<void> => {
    setError(null);
    try {
      await api(`/mod/workers/invites/${id}`, { method: 'DELETE' });
      onChanged(t('mod.workers.inviteWithdrawn'));
    } catch (e) {
      setError(modError(e));
    }
  };
  return (
    <div className="flex flex-col gap-2">
      <p className="label">{t('mod.workers.invitesOpen')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      <ul className="flex flex-col gap-1">
        {invites.map((inv) => (
          <li key={inv.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="min-w-0 truncate">{inv.name}</span>
            <Pill tone={inv.trust === 'lender' ? 'warn' : 'default'}>{inv.trust === 'lender' ? t('mod.workers.trustLender') : t('mod.workers.trustOwn')}</Pill>
            <span className="text-xs text-ink-muted">{t('mod.workers.inviteExpires', { when: `${localDate(inv.expires * 1000, lang)} ${localTime(inv.expires * 1000, lang)}` })}</span>
            <button type="button" className="btn-ghost px-3 py-1 text-sm" onClick={() => void withdraw(inv.id)}>
              {t('mod.workers.inviteWithdraw')}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** A new invite: a name, and whose computer it will be — ours, or one lent by someone we trust. */
function InviteForm({ onInvited, onError }: { onInvited: (name: string, code: string, expires: number) => void; onError: (text: string) => void }) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [trust, setTrust] = useState<'own' | 'lender' | ''>('');
  const [busy, setBusy] = useState(false);
  const create = async (): Promise<void> => {
    setBusy(true);
    try {
      const r = await api<{ invite: ModWorkerInvite; code: string }>('/mod/workers/invites', { body: { name: name.trim(), trust } });
      onInvited(r.invite.name, r.code, r.invite.expires);
      setName('');
      setTrust('');
    } catch (e) {
      onError(modError(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <form
      className="flex flex-col gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        void create();
      }}
    >
      <p className="label">{t('mod.workers.inviteTitle')}</p>
      <Field label={t('mod.workers.name')}>
        <input className="field" maxLength={60} value={name} placeholder={t('mod.workers.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
      </Field>
      <fieldset className="flex flex-col gap-1 text-sm">
        <legend className="mb-1 text-ink-muted">{t('mod.workers.inviteTrust')}</legend>
        {(['own', 'lender'] as const).map((v) => (
          <label key={v} className="flex items-start gap-2">
            <input type="radio" name="worker-trust" className="mt-1 accent-accent-fill" checked={trust === v} onChange={() => setTrust(v)} />
            <span>{v === 'own' ? t('mod.workers.inviteOwn') : t('mod.workers.inviteLender')}</span>
          </label>
        ))}
      </fieldset>
      <div>
        <button type="submit" className="btn-primary" disabled={busy || name.trim() === '' || trust === ''}>
          {t('mod.workers.inviteCreate')}
        </button>
      </div>
    </form>
  );
}

/** The old voice worker (protocol 1): one of ours, added with a key shown once. */
function KeyedForm({ onAdded, onError }: { onAdded: (name: string, key: string) => void; onError: (text: string) => void }) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const add = async (): Promise<void> => {
    setBusy(true);
    try {
      const r = await api<{ worker: ModWorker; key: string }>('/mod/workers', { body: { name: name.trim() } });
      onAdded(r.worker.name, r.key);
      setName('');
    } catch (e) {
      onError(modError(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <form
      className="mt-2 flex flex-wrap items-end gap-2"
      onSubmit={(e) => {
        e.preventDefault();
        void add();
      }}
    >
      <div className="min-w-0 flex-1">
        <Field label={t('mod.workers.keyedName')}>
          <input className="field" maxLength={60} value={name} placeholder={t('mod.workers.namePlaceholder')} onChange={(e) => setName(e.target.value)} />
        </Field>
      </div>
      <button type="submit" className="btn-primary" disabled={busy || name.trim() === ''}>
        {t('mod.workers.add')}
      </button>
    </form>
  );
}

/** Copies a text; without a clipboard the text stays selectable. */
function useCopy(): [boolean, (text: string) => Promise<void>] {
  const [copied, setCopied] = useState(false);
  const copy = async (text: string): Promise<void> => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };
  return [copied, copy];
}

/** An invite's code, shown this once, with what to run on the computer. */
function InviteBox({ name, code, expires, onClose }: { name: string; code: string; expires: number; onClose: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const command = joinCommand(code, window.location.origin);
  const [copied, copy] = useCopy();
  return (
    <div className="card-inset flex flex-col gap-2 border border-accent/40 p-3" role="status">
      <p className="label">{t('mod.workers.inviteBoxTitle', { name })}</p>
      <p className="text-sm">{t('mod.workers.inviteOnce', { when: `${localDate(expires * 1000, lang)} ${localTime(expires * 1000, lang)}` })}</p>
      <p className="font-mono text-lg tracking-wider" aria-label={t('mod.workers.inviteCode')}>
        {code}
      </p>
      <p className="text-sm">{t('mod.workers.inviteCommand')}</p>
      <pre className="overflow-x-auto rounded-lg bg-soft p-2 text-xs" aria-label={t('mod.workers.inviteCommandLabel')}>
        <code>{command}</code>
      </pre>
      <p className="text-xs text-ink-faint">{t('mod.workers.inviteDocker')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-primary px-3 py-1.5 text-sm" onClick={() => void copy(command)}>
          {copied ? t('mod.workers.copied') : t('mod.workers.copy')}
        </button>
        <button type="button" className="btn-ghost px-3 py-1.5 text-sm" onClick={onClose}>
          {t('mod.workers.keyDone')}
        </button>
      </div>
    </div>
  );
}

/** The key, shown this once, and the lines that go into the old voice worker's config. */
function KeyBox({ name, keyValue, onClose }: { name: string; keyValue: string; onClose: () => void }) {
  const { t } = useTranslation();
  const snippet = configSnippet(keyValue, window.location.origin);
  const [copied, copy] = useCopy();
  return (
    <div className="card-inset flex flex-col gap-2 border border-accent/40 p-3" role="status">
      <p className="label">{t('mod.workers.keyTitle', { name })}</p>
      <p className="text-sm">{t('mod.workers.keyOnce')}</p>
      <pre className="overflow-x-auto rounded-lg bg-soft p-2 text-xs" aria-label={t('mod.workers.snippet')}>
        <code>{snippet}</code>
      </pre>
      <p className="text-xs text-ink-faint">{t('mod.workers.snippetHint')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn-primary px-3 py-1.5 text-sm" onClick={() => void copy(snippet)}>
          {copied ? t('mod.workers.copied') : t('mod.workers.copy')}
        </button>
        <button type="button" className="btn-ghost px-3 py-1.5 text-sm" onClick={onClose}>
          {t('mod.workers.keyDone')}
        </button>
      </div>
    </div>
  );
}
