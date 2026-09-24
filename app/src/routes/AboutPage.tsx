import { Fragment, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router-dom';
import type { Lang } from '@arche/shared';
import { stationTexts, type Block } from '@/content/legal';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { ConfirmButton } from '@/components/mod/ui';
import { RadioIcon } from '@/components/common/icons';

/**
 * The station page behind the logo: what ARCHE is, the imprint (Impressum)
 * and the privacy policy (Datenschutz), plus the privacy settings they
 * promise. /impressum and /datenschutz open it at their section — both must
 * be reachable directly and at all times.
 */
export function AboutPage({ section }: { section?: 'impressum' | 'datenschutz' }) {
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
          <span className="text-3xl font-light tracking-logo">ARCHE</span>
          <RadioIcon size={28} className="text-brand-bright" />
        </div>
        <p className="rounded-xl border border-brand/30 bg-brand/10 px-4 py-3 text-sm leading-relaxed text-ink">{texts.notice}</p>
        <Blocks blocks={texts.about} />
      </section>

      <nav className="flex flex-wrap gap-3 px-1 text-sm" aria-label={t('about.legal')}>
        <a href="#impressum" className="text-brand-bright underline">{t('about.imprint')}</a>
        <a href="#datenschutz" className="text-brand-bright underline">{t('about.privacy')}</a>
        <a href="#einstellungen" className="text-brand-bright underline">{t('about.settings')}</a>
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

      <section id="einstellungen" className="card flex scroll-mt-4 flex-col gap-4 p-5">
        <h2 className="text-xl font-semibold">{t('about.settings')}</h2>
        <PrivacySettings />
      </section>
    </div>
  );
}

function Blocks({ blocks }: { blocks: Block[] }) {
  return (
    <div className="flex flex-col gap-4">
      {blocks.map((b) => (
        <div key={b.h}>
          <h3 className="font-semibold text-ink">{b.h}</h3>
          {b.p.split('\n').map((line, i) => (
            <p key={i} className="mt-1 text-sm leading-relaxed text-ink-muted">
              <Linked text={line} />
            </p>
          ))}
        </div>
      ))}
    </div>
  );
}

// A URL runs to the next space or ")"; an address ends at its last domain label.
const LINK = /(https?:\/\/[^\s)]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)/g;

/** URLs and e-mail addresses in a legal text as links (nothing else is markup). */
function Linked({ text }: { text: string }) {
  const parts = text.split(LINK);
  return (
    <>
      {parts.map((part, i) => {
        if (i % 2 === 0) return <Fragment key={i}>{part}</Fragment>;
        const href = part.startsWith('http') ? part : `mailto:${part}`;
        return (
          <a key={i} href={href} className="text-brand-bright underline" target={href.startsWith('http') ? '_blank' : undefined} rel="noreferrer">
            {part}
          </a>
        );
      })}
    </>
  );
}

const DEVICE_KEYS = ['arche.device', 'arche.settings', 'arche.passphrase'];

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

  const forget = (): void => {
    for (const key of DEVICE_KEYS) {
      try {
        localStorage.removeItem(key);
      } catch {
        /* storage unavailable: nothing to delete */
      }
    }
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
        <ConfirmButton className="btn-ghost text-heart" label={t('about.forget')} question={t('about.forgetConfirm')} onConfirm={forget} />
      </div>
    </div>
  );
}
