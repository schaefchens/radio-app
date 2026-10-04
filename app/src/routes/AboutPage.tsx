import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router-dom';
import type { Lang } from '@arche/shared';
import { stationTexts } from '@/content/legal';
import { rulesText } from '@/content/rules';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { ConfirmButton } from '@/components/mod/ui';
import { RadioIcon } from '@/components/common/icons';
import { ShareStationButton } from '@/components/common/ShareStationButton';
import { clearDeviceData } from '@/lib/deviceData';
import { Blocks } from '@/components/common/Blocks';

/**
 * The station page behind the logo: what Arche Radio is, the imprint (Impressum)
 * and the privacy policy (Datenschutz), plus the privacy settings they
 * promise. /impressum and /datenschutz open it at their section — both must
 * be reachable directly and at all times.
 */
export function AboutPage({ section }: { section?: 'impressum' | 'datenschutz' | 'regeln' }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const host = useSession((s) => s.channels?.channels.find((c) => c.main)?.host.name ?? 'Hope');
  const texts = stationTexts(lang, host);
  const { hash } = useLocation();

  useEffect(() => {
    const id = section ?? hash.replace(/^#/, '');
    if (id) document.getElementById(id)?.scrollIntoView({ block: 'start' });
    else window.scrollTo({ top: 0 });
  }, [section, hash]);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 pt-2">
      <section id="about" className="card flex flex-col gap-4 p-5">
        <div className="flex items-center gap-2 text-ink">
          <span className="font-serif text-3xl font-bold tracking-tight">Arche Radio</span>
          <RadioIcon size={28} className="text-accent" />
        </div>
        <p className="rounded-xl border border-accent-fill/30 bg-accent-fill/10 px-4 py-3 text-sm leading-relaxed text-ink">{texts.notice}</p>
        <Blocks blocks={texts.about} />
        <ShareStationButton />
      </section>

      <nav className="flex flex-wrap gap-3 px-1 text-sm" aria-label={t('about.legal')}>
        <a href="#impressum" className="text-accent underline">{t('about.imprint')}</a>
        <a href="#datenschutz" className="text-accent underline">{t('about.privacy')}</a>
        <a href="#regeln" className="text-accent underline">{t('about.rules')}</a>
        <a href="#einstellungen" className="text-accent underline">{t('about.settings')}</a>
      </nav>

      <section id="impressum" className="card flex scroll-mt-4 flex-col gap-4 p-5">
        <h2 className="text-xl font-semibold">{t('about.imprint')}</h2>
        {texts.bindingNote && <p className="text-xs text-ink-faint">{texts.bindingNote}</p>}
        <Blocks blocks={texts.imprint} />
      </section>

      <section id="datenschutz" className="card flex scroll-mt-4 flex-col gap-4 p-5">
        <h2 className="text-xl font-semibold">{t('about.privacy')}</h2>
        {texts.bindingNote && <p className="text-xs text-ink-faint">{texts.bindingNote}</p>}
        <Blocks blocks={texts.privacy} />
      </section>

      <section id="regeln" className="card flex scroll-mt-4 flex-col gap-4 p-5">
        <h2 className="text-xl font-semibold">{t('about.rules')}</h2>
        {texts.bindingNote && <p className="text-xs text-ink-faint">{texts.bindingNote}</p>}
        <Blocks blocks={rulesText(lang)} />
      </section>

      <section id="einstellungen" className="card flex scroll-mt-4 flex-col gap-4 p-5">
        <h2 className="text-xl font-semibold">{t('about.settings')}</h2>
        <PrivacySettings />
      </section>
    </div>
  );
}

/** What the privacy policy promises the listener can do here, without writing to anyone. */
function PrivacySettings() {
  const { t } = useTranslation();
  const consent = useSettings((s) => s.consent);
  const setConsent = useSettings((s) => s.setConsent);

  const withdraw = (): void => {
    setConsent(false);
    // The IFrame API is already in the page: only a reload unloads it.
    window.location.reload();
  };

  const forget = async (): Promise<void> => {
    await clearDeviceData();
    window.location.assign('/');
  };

  return (
    <div className="flex flex-col gap-4 text-sm">
      <div className="flex flex-wrap items-center gap-3">
        <p className="flex-1 text-ink-muted">{consent ? t('about.consentOn') : t('about.consentOff')}</p>
        {consent && (
          <button type="button" className="btn-ghost" onClick={withdraw}>
            {t('about.withdraw')}
          </button>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <p className="flex-1 text-ink-muted">{t('about.forgetHint')}</p>
        <ConfirmButton className="btn-ghost text-heart" label={t('about.forget')} question={t('about.forgetConfirm')} onConfirm={() => void forget()} />
      </div>
      <p className="text-ink-muted">
        <Link to="/konto-loeschen" className="text-accent underline">
          {t('about.deleteAccount')} →
        </Link>
      </p>
    </div>
  );
}
