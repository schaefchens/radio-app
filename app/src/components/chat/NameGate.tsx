import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import { guessCountry } from '@/lib/format';
import { useSession, type IdentityView } from '@/store/session';
import { CountrySelect } from '@/components/profile/CountrySelect';

/** A room needs a name to show next to what you write. */
export function NameGate() {
  const { t } = useTranslation();
  const setIdentity = useSession((s) => s.setIdentity);
  const [name, setName] = useState('');
  const [country, setCountry] = useState(guessCountry);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const save = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ identity: IdentityView }>('/me', { method: 'PATCH', body: { name: name.trim(), country } });
      setIdentity(r.identity);
    } catch (e) {
      setError(errorText(e instanceof ApiError ? e.code : 'generic'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form
      className="card mx-auto flex w-full max-w-md flex-col gap-4 p-5"
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <p className="text-ink">{t('chat.nameFirst')}</p>
      <label className="flex flex-col">
        <span className="label">{t('profile.name')}</span>
        <input className="field" maxLength={30} autoComplete="nickname" value={name} onChange={(e) => setName(e.target.value)} />
      </label>
      <label className="flex flex-col">
        <span className="label">{t('profile.country')}</span>
        <CountrySelect value={country} onChange={setCountry} />
      </label>
      {error && <p className="text-sm text-heart">{error}</p>}
      <button type="submit" className="btn-primary" disabled={busy || name.trim().length < 2}>
        {t('chat.continue')}
      </button>
    </form>
  );
}
