import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import type { Lang } from '@arche/shared';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { LockIcon, TileIcon } from '@/components/common/icons';
import { useServerNow } from '@/components/home/useServerNow';
import { useDays } from '@/components/schedule/useDays';
import { addDays, stationDate } from '@/lib/format';
import { whenText } from '@/i18n';
import { TILES, TILE_TONE, TILE_TYPES, lockedWhy, nextOpening, tileOpen, type Tile } from '@/lib/intake';
import { DAY_OFFSETS } from '@/lib/reminderPlan';
import { useRadio } from '@/store/radio';
import { useSession } from '@/store/session';
import { useSheets } from '@/store/sheets';

/**
 * A locked tile, tapped: why it takes nothing now, when it will again — the
 * next program that takes it, from the day files the site publishes (a week
 * ahead) — and what can be sent right now instead. Listeners tapped locked
 * tiles and thought them broken.
 */
export function LockedSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const tile = useSheets((s) => s.locked);
  // A name of its own, never the tile's: that is its form's, and two dialogs
  // of one name are one too many for whoever looks for the form.
  const opened = useRadio((s) => tileOpen(tile, s.engine.submissions));
  // From the first tap on, and kept: closing slides the words away with the
  // sheet, and the day files are fetched once.
  const [asked, setAsked] = useState(open);
  if (open && !asked) setAsked(true);
  return (
    <BottomSheet open={open} onClose={onClose} title={opened ? t('submit.locked.openNow') : t('submit.locked.now')}>
      <BottomSheetBody>{asked && <LockedInfo tile={tile} onClose={onClose} />}</BottomSheetBody>
    </BottomSheet>
  );
}

function LockedInfo({ tile, onClose }: { tile: Tile; onClose: () => void }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const navigate = useNavigate();
  const engine = useRadio((s) => s.engine);
  const channel = useSession((s) => s.channels?.channels.find((c) => c.id === engine.channel));
  const openTile = useSheets((s) => s.openTile);
  const now = useServerNow(30_000);
  const today = stationDate(now, channel?.tz ?? 'Europe/Berlin');
  // Yesterday too: it tells whether today begins a program or continues one.
  const dates = DAY_OFFSETS.map((d) => addDays(today, d));
  const days = useDays(engine.channel, dates);

  const types = TILE_TYPES[tile];
  const locked = !tileOpen(tile, engine.submissions);
  const why = lockedWhy(types, engine.submissions, engine.why);
  const what = t(`submit.${tile}.what`);
  const program = engine.program?.title[lang];
  const next = nextOpening(types, dates, days, now);
  const loading = !next && dates.some((d) => days[d] === undefined);
  const instead = TILES.filter((other) => tileOpen(other, engine.submissions));

  return (
    <div className="flex flex-col gap-4">
      {locked && (
        <div className="flex flex-col gap-2">
          <p className="flex items-center gap-2 font-semibold text-ink">
            <LockIcon size={18} />
            {t(`submit.${tile}.title`)}
          </p>
          <p className="text-sm leading-relaxed text-ink">{program ? t(`submit.locked.${why}`, { program, what }) : t('submit.locked.noProgram', { what })}</p>
          <p className="text-sm font-semibold leading-relaxed text-accent">
            {next
              ? t(why === 'full' ? 'submit.locked.nextFull' : 'submit.locked.next', { when: whenText(next.start, now), program: next.program.title[lang] })
              : loading
                ? t('common.loading')
                : t('submit.locked.none', { what })}
          </p>
        </div>
      )}
      {instead.length > 0 && (
        <div>
          <p className="label">{t('submit.locked.instead')}</p>
          <div className="flex flex-wrap gap-2">
            {instead.map((other) => (
              <button key={other} type="button" className={clsx('action-chip', TILE_TONE[other])} onClick={() => openTile(other)}>
                <TileIcon tile={other} />
                {t(`submit.${other}.title`)}
              </button>
            ))}
          </div>
        </div>
      )}
      <p className="text-xs leading-relaxed text-ink-muted">{t('submit.locked.how')}</p>
      <div className="flex gap-2">
        <button
          type="button"
          className="btn-ghost flex-1"
          onClick={() => {
            onClose();
            navigate('/schedule');
          }}
        >
          {t('submit.locked.schedule')}
        </button>
        <button type="button" className="btn-primary flex-1" onClick={onClose}>
          {t('submit.locked.ok')}
        </button>
      </div>
    </div>
  );
}
