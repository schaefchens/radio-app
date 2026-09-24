import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import type { Lang, Wall, WallEntry } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { usePrayed, withMine } from '@/store/prayed';
import { prayAlong } from '@/lib/radio';
import { ago } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { CheckIcon, PrayIcon } from '@/components/common/icons';

const NONE: string[] = [];

/**
 * The program's prayer wall, in place of the community voices while it is
 * up: the requests appear as they are approved, newest first; the ones the
 * host is praying for right now are marked; 🙏 prays along.
 */
export function PrayerWall({ wall }: { wall: Wall }) {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const item = useRadio((s) => s.engine.item);
  const praying = item?.type === 'host' ? item.prayers : NONE;
  const entries = [...wall.entries].reverse();

  return (
    <section className="flex flex-col gap-2" aria-label={t('wall.title')}>
      <div className="flex items-baseline justify-between gap-2 px-1">
        <h2 className="text-lg font-semibold">{t('wall.title')}</h2>
        <span className="truncate text-xs text-ink-muted">
          {wall.title[lang]}
          {entries.length > 0 && ` · ${t('wall.count', { count: entries.length })}`}
        </span>
      </div>
      {entries.length === 0 ? (
        <p className="card px-4 py-3 text-sm text-ink-muted">{wall.open ? t('wall.empty') : t('wall.none')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {entries.map((e) => (
            <WallCard key={e.id} entry={e} praying={praying.includes(e.id)} locale={lang} />
          ))}
        </ul>
      )}
    </section>
  );
}

export function WallCard({ entry, praying, locale, compact, className }: { entry: WallEntry; praying: boolean; locale: Lang; compact?: boolean; className?: string }) {
  const { t } = useTranslation();
  const now = useServerNow(30_000);
  const tappedAt = usePrayed((s) => s.at[entry.id]);
  const mine = tappedAt !== undefined;
  const n = withMine(entry.n, tappedAt);
  return (
    <li
      className={clsx(
        'card relative flex items-start gap-3 px-3 py-2.5 animate-fly-in transition-shadow',
        praying && 'ring-2 ring-brand-bright/70',
        compact && 'bg-night-deep/70 backdrop-blur',
        className,
      )}
    >
      <div className="min-w-0 flex-1">
        <p className="truncate text-xs text-ink-muted">
          <span className="font-semibold text-brand-bright">{entry.name || t('wall.anonymous')}</span>
          {entry.place && ` · ${entry.place}`}
          {!compact && entry.at > 0 && ` · ${ago(entry.at, now, locale)}`}
        </p>
        <p className={clsx('text-sm text-ink', compact ? 'line-clamp-2' : 'line-clamp-4')}>{entry.text}</p>
        {(praying || entry.prayed) && (
          <p className="mt-1 flex items-center gap-1 text-xs text-brand-bright">
            {praying ? t('wall.prayingNow') : (
              <>
                <CheckIcon size={14} /> {t('wall.prayed')}
              </>
            )}
          </p>
        )}
      </div>
      <button
        type="button"
        aria-pressed={mine}
        aria-label={mine ? t('wall.prayedAlong', { count: n }) : t('wall.prayAlong', { count: n })}
        onClick={() => prayAlong(entry.id, entry.n)}
        className={clsx(
          'flex shrink-0 flex-col items-center rounded-xl border px-2.5 py-1.5 text-xs tabular-nums transition-colors',
          mine ? 'border-brand/60 bg-brand/20 text-brand-bright' : 'border-night-line/30 text-ink-muted hover:border-brand/60 hover:bg-brand/20 hover:text-brand-bright',
        )}
      >
        <PrayIcon size={20} filled={mine} />
        {n > 0 && <span>{n}</span>}
      </button>
    </li>
  );
}
