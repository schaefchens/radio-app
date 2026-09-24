import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useSession, isModerator } from '@/store/session';
import { useSettings } from '@/store/settings';
import { isIOS } from '@/lib/platform';
import { wakeLockSupported } from '@/lib/wakeLock';
import { checkForUpdates } from '@/lib/pwaUpdate';
import { ProfileForm } from '@/components/profile/ProfileForm';
import { PassphrasePanel } from '@/components/profile/PassphrasePanel';
import { MySubmissions } from '@/components/profile/MySubmissions';
import { ShieldIcon, VolumeIcon } from '@/components/common/icons';

const IOS = isIOS();
const WAKE_LOCK = wakeLockSupported();

export function ProfilePage() {
  const { t, i18n } = useTranslation();
  const identity = useSession((s) => s.identity);
  // Kept here, not in the form: a new listener's first save creates their
  // identity, which remounts the form (it is keyed by identity).
  const [savedFor, setSavedFor] = useState<string | null>(null);
  const config = useSession((s) => s.config);
  const volume = useSettings((s) => s.volume);
  const setVolume = useSettings((s) => s.setVolume);
  const keepAwake = useSettings((s) => s.keepAwake);
  const setKeepAwake = useSettings((s) => s.setKeepAwake);
  const [checked, setChecked] = useState(false);
  const built = new Date(__BUILD_TIME__).toLocaleString(i18n.language);

  return (
    <div className="flex flex-col gap-4 pt-2">
      <h1 className="text-2xl font-semibold">{t('profile.title')}</h1>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="flex flex-col gap-4">
          <section className="card flex flex-col gap-4 p-4">
            <ProfileForm key={identity?.id ?? 'new'} identity={identity} saved={!!identity && savedFor === identity.id} onSaved={setSavedFor} />
            {!IOS && (
              <label className="flex items-center gap-3">
                <VolumeIcon size={20} className="shrink-0 text-ink-muted" />
                <span className="sr-only">{t('profile.volume')}</span>
                <input type="range" min={0} max={1} step={0.05} value={volume} onChange={(e) => setVolume(Number(e.target.value))} className="w-full accent-brand" aria-label={t('profile.volume')} />
              </label>
            )}
            {WAKE_LOCK && (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="h-4 w-4 accent-brand" checked={keepAwake} onChange={(e) => setKeepAwake(e.target.checked)} />
                {t('profile.keepAwake')}
              </label>
            )}
          </section>

          <section className="card flex flex-col gap-3 p-4">
            <h2 className="text-lg font-semibold">{t('profile.passphrase.title')}</h2>
            <PassphrasePanel identity={identity} />
          </section>

          {(isModerator(identity) || config?.setupNeeded) && (
            <section className="card flex flex-wrap gap-2 p-4">
              {isModerator(identity) && (
                <Link to="/mod" className="btn-primary">
                  <ShieldIcon size={16} />
                  {t('profile.moderation')}
                </Link>
              )}
              {config?.setupNeeded && (
                <Link to="/setup" className="btn-ghost">
                  {t('profile.setupLink')}
                </Link>
              )}
            </section>
          )}
        </div>

        <div className="flex flex-col gap-4">
          <section className="card flex flex-col gap-3 p-4">
            <h2 className="text-lg font-semibold">{t('status.title')}</h2>
            <MySubmissions />
          </section>

          <section className="card flex flex-col gap-2 p-4 text-sm">
            <h2 className="text-lg font-semibold">{t('profile.about')}</h2>
            <p className="text-ink-muted">{t('profile.version', { version: __GIT_COMMIT__ })}</p>
            <p className="text-xs text-ink-faint">{t('profile.built', { time: built })}</p>
            {identity && <p className="text-xs text-ink-faint">{t('profile.identity', { id: identity.id })}</p>}
            <div className="flex flex-wrap items-center gap-3 pt-1">
              <button
                type="button"
                className="btn-ghost"
                onClick={() => {
                  void checkForUpdates().then(() => setChecked(true));
                }}
              >
                {t('profile.checkUpdate')}
              </button>
              {checked && <span className="text-xs text-ink-muted">{t('profile.checked')}</span>}
            </div>
            <div className="flex flex-wrap gap-4 pt-2">
              {config?.imprint && (
                <a href={config.imprint} target="_blank" rel="noreferrer" className="text-brand-bright underline">
                  {t('profile.imprint')}
                </a>
              )}
              {config?.privacy && (
                <a href={config.privacy} target="_blank" rel="noreferrer" className="text-brand-bright underline">
                  {t('profile.privacy')}
                </a>
              )}
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}
