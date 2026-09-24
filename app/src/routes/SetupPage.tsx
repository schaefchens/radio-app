import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import { useSession, isModerator, type IdentityView } from '@/store/session';

/**
 * First-run administration. The webhosting has no shell, so the first admin
 * is claimed here with the one-time ADMIN_SETUP_KEY from the server's .env —
 * by a passphrase identity, so the role cannot be lost with a cleared browser.
 */
export function SetupPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const identity = useSession((s) => s.identity);
  const setIdentity = useSession((s) => s.setIdentity);
  const [needed, setNeeded] = useState<boolean | null>(null);
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    void api<{ needed: boolean }>('/setup')
      .then((r) => live && setNeeded(r.needed))
      .catch(() => live && setNeeded(false));
    return () => {
      live = false;
    };
  }, []);

  const claim = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await api<{ identity: IdentityView }>('/setup/admin', { body: { key: key.trim() } });
      const me = await api<{ identity: IdentityView | null }>('/me');
      setIdentity(me.identity);
      navigate('/mod');
    } catch (e) {
      setError(errorText(e instanceof ApiError ? e.code : 'generic'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex w-full max-w-lg flex-col gap-4 pt-2">
      <h1 className="text-2xl font-semibold">{t('setup.title')}</h1>
      <div className="card flex flex-col gap-4 p-5">
        {needed === null && <p className="text-sm text-ink-muted">{t('common.loading')}</p>}
        {needed === false && (
          <>
            <p className="text-sm text-ink-muted">{t('setup.notNeeded')}</p>
            {isModerator(identity) && (
              <Link to="/mod" className="btn-primary self-start">
                {t('setup.goMod')}
              </Link>
            )}
          </>
        )}
        {needed && !identity?.claimed && (
          <>
            <p className="text-sm text-ink">{t('setup.needPassphrase')}</p>
            <Link to="/profile" className="btn-primary self-start">
              {t('setup.goProfile')}
            </Link>
          </>
        )}
        {needed && identity?.claimed && (
          <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
              e.preventDefault();
              void claim();
            }}
          >
            <p className="text-sm text-ink-muted">{t('setup.intro')}</p>
            <label className="flex flex-col">
              <span className="label">{t('setup.key')}</span>
              <input className="field font-mono" autoComplete="off" autoCapitalize="none" spellCheck={false} value={key} onChange={(e) => setKey(e.target.value)} />
            </label>
            {error && <p className="text-sm text-heart">{error}</p>}
            <button type="submit" className="btn-primary" disabled={busy || key.trim().length < 32}>
              {t('setup.claim')}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
