import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { normalize, validPassphrase } from '@/lib/passphrase';
import { useSession } from '@/store/session';
import { DeleteAccountSheet } from '@/components/profile/DeleteAccountSheet';

/**
 * /konto-loeschen (/delete-account): how to delete an Arche Radio account,
 * also for someone who no longer has the app — Google Play wants a web page
 * for it. Here in this browser; anywhere with the 12 words; and what happens
 * without either.
 */
export function DeleteAccountPage() {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const [sheet, setSheet] = useState<'mine' | 'words' | null>(null);
  const [words, setWords] = useState('');
  const [done, setDone] = useState(false);
  const wordsOk = validPassphrase(normalize(words));

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 pt-2">
      <h1 className="text-2xl font-semibold">{t('account.page.title')}</h1>
      <p className="text-sm text-ink-muted">{t('account.page.intro')}</p>

      {done && <p className="card border-ok/40 px-4 py-3 text-sm text-ink">{t('account.page.done')}</p>}

      <section className="card flex flex-col gap-3 p-5 text-sm">
        <h2 className="text-lg font-semibold">{t('account.page.hereTitle')}</h2>
        {identity ? (
          <>
            <p className="text-ink-muted">{t('account.page.here')}</p>
            <button type="button" className="btn self-start bg-heart text-white hover:brightness-110" onClick={() => setSheet('mine')}>
              {t('account.button')}
            </button>
          </>
        ) : (
          <p className="text-ink-muted">{t('account.page.hereNone')}</p>
        )}
      </section>

      <section className="card flex flex-col gap-3 p-5 text-sm">
        <h2 className="text-lg font-semibold">{t('account.page.wordsTitle')}</h2>
        <p className="text-ink-muted">{t('account.page.words')}</p>
        <label className="label" htmlFor="delete-words">
          {t('account.page.wordsLabel')}
        </label>
        <textarea
          id="delete-words"
          className="field min-h-[88px]"
          autoComplete="off"
          autoCapitalize="none"
          spellCheck={false}
          value={words}
          onChange={(e) => setWords(e.target.value)}
        />
        <button type="button" className="btn self-start bg-heart text-white hover:brightness-110 disabled:opacity-50" disabled={!wordsOk} onClick={() => setSheet('words')}>
          {t('account.button')}
        </button>
      </section>

      <section className="card flex flex-col gap-3 p-5 text-sm">
        <h2 className="text-lg font-semibold">{t('account.page.neitherTitle')}</h2>
        <p className="text-ink-muted">{t('account.page.neither')}</p>
        <p className="text-ink-muted">
          {t('account.page.contact')}{' '}
          <a href="mailto:app.support@schaefchens.de" className="text-accent underline">
            app.support@schaefchens.de
          </a>{' '}
          · <Link to="/datenschutz" className="text-accent underline">{t('profile.privacy')}</Link>
        </p>
      </section>

      <DeleteAccountSheet open={sheet === 'mine'} onClose={() => setSheet(null)} />
      <DeleteAccountSheet
        open={sheet === 'words'}
        onClose={() => setSheet(null)}
        words={words}
        onDone={() => {
          setSheet(null);
          setWords('');
          setDone(true);
        }}
      />
    </div>
  );
}
