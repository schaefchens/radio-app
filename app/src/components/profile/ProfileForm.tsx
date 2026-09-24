import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang } from '@arche/shared';
import { api, ApiError } from '@/lib/api';
import { errorText } from '@/i18n';
import { guessCountry } from '@/lib/format';
import { useSession, type IdentityView } from '@/store/session';
import { useSettings } from '@/store/settings';
import { CountrySelect } from './CountrySelect';

/**
 * Name, country, language. Keyed per identity by the page, so it starts from
 * the saved values (also after a passphrase login switches the identity).
 */
export function ProfileForm({ identity, saved, onSaved }: { identity: IdentityView | null; saved: boolean; onSaved: (id: string) => void }) {
  const { t } = useTranslation();
  const setIdentity = useSession((s) => s.setIdentity);
  const lang = useSettings((s) => s.lang);
  const setLang = useSettings((s) => s.setLang);
  const [name, setName] = useState(identity?.name ?? '');
  const [country, setCountry] = useState(() => identity?.country || guessCountry());
  const [language, setLanguage] = useState<Lang>(lang);
  const [state, setState] = useState<'idle' | 'busy' | 'edited'>('idle');
  const [error, setError] = useState<string | null>(null);

  const save = async (): Promise<void> => {
    setState('busy');
    setError(null);
    setLang(language);
    try {
      const r = await api<{ identity: IdentityView }>('/me', { method: 'PATCH', body: { name: name.trim(), country, lang: language } });
      setIdentity(r.identity);
      onSaved(r.identity.id);
      setState('idle');
    } catch (e) {
      setError(errorText(e instanceof ApiError ? e.code : 'generic'));
      // Not saved: an earlier "Saved" must not come back with the error.
      setState('edited');
    }
  };

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <label className="flex flex-col">
          <span className="label">{t('profile.name')}</span>
          <input className="field" maxLength={30} value={name} onChange={(e) => { setName(e.target.value); setState('edited'); }} />
        </label>
        <label className="flex flex-col">
          <span className="label">{t('profile.country')}</span>
          <CountrySelect value={country} onChange={(v) => { setCountry(v); setState('edited'); }} />
        </label>
        <label className="flex flex-col">
          <span className="label">{t('profile.language')}</span>
          <select className="field" value={language} onChange={(e) => { setLanguage(e.target.value === 'de' ? 'de' : 'en'); setState('edited'); }}>
            <option value="en">English</option>
            <option value="de">Deutsch</option>
          </select>
        </label>
      </div>
      {error && <p className="text-sm text-heart">{error}</p>}
      <div className="flex items-center gap-3">
        <button type="submit" className="btn-primary" disabled={state === 'busy'}>
          {t('profile.save')}
        </button>
        {saved && state === 'idle' && <span className="text-sm text-emerald-300">{t('profile.saved')}</span>}
      </div>
    </form>
  );
}
