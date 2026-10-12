import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSheets } from '@/store/sheets';
import { VideoRequestSheet } from '@/components/submit/VideoRequestSheet';
import { PrayerSheet } from '@/components/submit/PrayerSheet';
import { RecordSheet } from '@/components/submit/RecordSheet';
import { PraySheet } from '@/components/submit/PraySheet';
import { LockedSheet } from '@/components/submit/LockedSheet';
import { LockIcon, TileIcon } from '@/components/common/icons';
import { localTime } from '@/lib/format';
import { TILES, TILE_TONE, TILE_TYPES, daysAhead, lockedWhy, nextOpening, tileState } from '@/lib/intake';
import { useServerNow } from './useServerNow';

/** The submission sheets, mounted once, on Home (AppShell); a prayer hour's Pray sheet opens from the stage. */
export function SubmitSheets() {
  const open = useSheets((s) => s.open);
  const recordKind = useSheets((s) => s.recordKind);
  const show = useSheets((s) => s.show);
  const close = useSheets((s) => s.close);
  return (
    <>
      <VideoRequestSheet kind="song" open={open === 'song'} onClose={close} />
      <VideoRequestSheet kind="video" open={open === 'video'} onClose={close} />
      <PrayerSheet open={open === 'prayer'} onClose={close} onRecord={() => show('record', 'prayer')} />
      <RecordSheet open={open === 'record'} onClose={close} initialKind={recordKind} />
      <PraySheet open={open === 'pray'} onClose={close} />
      <LockedSheet open={open === 'locked'} onClose={close} />
    </>
  );
}

/**
 * The four tiles of the design; each follows what the program on air
 * accepts (the fourth, a video — a preaching, a testimony, a mission video or
 * a film —, only a program of such a format; the chat is in the menu). A
 * locked one carries a lock and, tapped, says why and when again (LockedSheet):
 * listeners took a tile that did nothing for a broken one. Its hint says when
 * it opens again where today's plan knows it.
 * `beforeOpen` lets the phone dock turn a first tap on a folded tile into
 * "unfold" (it returns true when it took the tap). `place="hero"` is the same
 * four once more in the desktop scenery, where the tiles below the page are
 * out of sight: small, the hint only on hover (shell.css).
 */
export function SubmitTiles({ beforeOpen, place = 'scene' }: { beforeOpen?: () => boolean; place?: 'scene' | 'hero' }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const submissions = useRadio((s) => s.engine.submissions);
  const why = useRadio((s) => s.engine.why);
  const today = useRadio((s) => s.today);
  // Before the first minute file every tile would look closed: a tap then
  // opens its form (the server checks every send anyway), not a wrong "not now".
  const loaded = useRadio((s) => s.engine.hasData);
  const openTile = useSheets((s) => s.openTile);
  const explain = useSheets((s) => s.explain);
  const now = useServerNow(60_000);

  return (
    <div className={place === 'hero' ? 'hero-actions' : 'actions'} role="group" aria-label={t('submit.label')}>
      {TILES.map((tile) => {
        const types = TILE_TYPES[tile];
        const state = tileState(types, submissions);
        const usable = !loaded || state === 'open' || state === 'closing';
        const reason = lockedWhy(types, submissions, why);
        // Why, said short: also the locked tile's name for screen readers.
        const short = state === 'off' ? t('submit.notNow') : reason === 'full' ? t('submit.full') : t('submit.closed');
        // A full one may open before the next program does: no time for it.
        const opening = !usable && reason !== 'full' && today ? nextOpening(types, [today.date], { [today.date]: today }, now) : null;
        const hint = usable
          ? t(`submit.${tile}.subtitle`)
          : opening && daysAhead(opening.start, now) === 0
            ? t('submit.againAt', { time: localTime(opening.start, lang) })
            : short;
        return (
          <button
            key={tile}
            type="button"
            className={clsx('action', TILE_TONE[tile])}
            data-locked={usable ? undefined : ''}
            aria-label={usable ? t(`submit.${tile}.title`) : `${t(`submit.${tile}.title`)} · ${short}`}
            title={place === 'hero' ? hint : undefined}
            onClick={() => {
              if (beforeOpen?.()) return;
              if (usable) openTile(tile);
              else explain(tile);
            }}
          >
            <TileIcon tile={tile} />
            <span className="action-copy">
              <strong>{t(`submit.${tile}.title`)}</strong>
              <small>{hint}</small>
            </span>
            {loaded && state === 'closing' && <span className="action-badge">{t('submit.closing')}</span>}
            {!usable && (
              <span className="action-lock" aria-hidden="true">
                <LockIcon />
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
