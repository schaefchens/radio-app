import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import { deriveCredential, generatePassphrase, normalize, validPassphrase } from '@/lib/passphrase';
import { resetDevice } from '@/lib/device';
import { useSession, type IdentityView } from '@/store/session';
import { ConfirmButton } from '@/components/mod/ui';

/** The words are kept on this device only, so "show my words" works here. */
export const PASSPHRASE_KEY = 'arche.passphrase';

function readWords(): string | null {
  try {
    return localStorage.getItem(PASSPHRASE_KEY);
  } catch {
    return null;
  }
}

function keepWords(words: string): void {
  try {
    localStorage.setItem(PASSPHRASE_KEY, normalize(words));
  } catch {
    /* storage unavailable: the listener still has them written down */
  }
}

type Mode = 'menu' | 'create' | 'enter';

export function PassphrasePanel({ identity }: { identity: IdentityView | null }) {
  const { t } = useTranslation();
  const setIdentity = useSession((s) => s.setIdentity);
  const [mode, setMode] = useState<Mode>('menu');
  const [words, setWords] = useState('');
  const [typed, setTyped] = useState('');
  const [written, setWritten] = useState(false);
  const [copied, setCopied] = useState(false);
  const [shown, setShown] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const call = async (path: '/identity/claim' | '/identity/login', phrase: string): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ identity: IdentityView }>(path, { body: deriveCredential(phrase) });
      keepWords(phrase);
      setIdentity(r.identity);
      setMode('menu');
      setWords('');
      setTyped('');
    } catch (e) {
      setError(errorText(e instanceof ApiError ? e.code : 'generic'));
    } finally {
      setBusy(false);
    }
  };

  const logout = (): void => {
    resetDevice();
    try {
      localStorage.removeItem(PASSPHRASE_KEY);
    } catch {
      /* ignore */
    }
    window.location.reload();
  };

  if (identity?.claimed) {
    const stored = readWords();
    return (
      <div className="flex flex-col gap-3">
        <p className="rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{t('profile.passphrase.claimed')}</p>
        {stored && (
          <div className="flex flex-col gap-2">
            <button type="button" className="btn-ghost self-start" onClick={() => setShown((s) => !s)}>
              {shown ? t('profile.passphrase.hide') : t('profile.passphrase.show')}
            </button>
            {shown && <WordGrid words={stored} />}
          </div>
        )}
        <ConfirmButton className="btn-ghost self-start text-heart" label={t('profile.passphrase.logout')} question={t('profile.passphrase.logoutConfirm')} onConfirm={logout} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm leading-relaxed text-ink-muted">{t('profile.passphrase.intro')}</p>
      {mode === 'menu' && (
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            className="btn-primary"
            onClick={() => {
              setWords(generatePassphrase());
              setWritten(false);
              setCopied(false);
              setError(null);
              setMode('create');
            }}
          >
            {t('profile.passphrase.create')}
          </button>
          <button type="button" className="btn-ghost" onClick={() => { setError(null); setMode('enter'); }}>
            {t('profile.passphrase.enter')}
          </button>
        </div>
      )}

      {mode === 'create' && (
        <div className="flex flex-col gap-3">
          <WordGrid words={words} />
          <button
            type="button"
            className="btn-ghost self-start"
            onClick={() => {
              void navigator.clipboard?.writeText(words).then(() => setCopied(true));
            }}
          >
            {copied ? t('profile.passphrase.copied') : t('profile.passphrase.copy')}
          </button>
          <label className="flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" className="mt-1 h-4 w-4 accent-brand" checked={written} onChange={(e) => setWritten(e.target.checked)} />
            {t('profile.passphrase.confirm')}
          </label>
          {error && <p className="text-sm text-heart">{error}</p>}
          <div className="flex gap-2">
            <button type="button" className="btn-primary" disabled={!written || busy} onClick={() => void call('/identity/claim', words)}>
              {t('profile.save')}
            </button>
            <button type="button" className="btn-ghost" onClick={() => setMode('menu')}>
              {t('submit.cancel')}
            </button>
          </div>
        </div>
      )}

      {mode === 'enter' && (
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            if (validPassphrase(typed)) void call('/identity/login', typed);
            else setError(t('profile.passphrase.invalid'));
          }}
        >
          <label className="flex flex-col">
            <span className="label">{t('profile.passphrase.words')}</span>
            <textarea className="field min-h-[96px] font-mono" autoCapitalize="none" autoCorrect="off" spellCheck={false} value={typed} onChange={(e) => setTyped(e.target.value)} />
          </label>
          {error && <p className="text-sm text-heart">{error}</p>}
          <div className="flex gap-2">
            <button type="submit" className="btn-primary" disabled={busy}>
              {t('profile.passphrase.login')}
            </button>
            <button type="button" className="btn-ghost" onClick={() => setMode('menu')}>
              {t('submit.cancel')}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

export function WordGrid({ words }: { words: string }) {
  const list = normalize(words).split(' ');
  return (
    <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {list.map((w, i) => (
        <li key={i} className={clsx('card-inset flex items-center gap-2 px-3 py-2 font-mono text-sm')}>
          <span className="w-5 text-right text-xs text-ink-faint">{i + 1}</span>
          <span className="text-ink">{w}</span>
        </li>
      ))}
    </ol>
  );
}
