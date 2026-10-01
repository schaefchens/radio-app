import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { WallEntry } from '@arche/shared';
import { useRadio } from '@/store/radio';
import { useSheets } from '@/store/sheets';
import { reactVoice } from '@/lib/radio';
import { dayKey } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { usePrayingWall } from './usePrayingWall';
import { useRotation } from './useRotation';
import { Reactions } from './Reactions';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { PrayIcon } from '@/components/common/icons';

/**
 * The prayer wall on desktop, under the player: one prayer request at a
 * time, the next one every few seconds (the phone shows it in the carousel);
 * while the host prays for one of them, that one, "Praying now". Requests
 * come from live.json: typed, approved, and shown only with the sender's yes;
 * anonymous, the text and the day.
 */
export function DesktopPrayerWall({ onMore }: { onMore: () => void }) {
  const { t } = useTranslation();
  const { wall, praying, now } = usePrayingWall();
  const body = useRef<HTMLDivElement>(null);
  const [picking, setPicking] = useState(false);
  const [nudge, setNudge] = useState(0);
  const onActivity = useCallback((open: boolean) => {
    setPicking(open);
    setNudge((n) => n + 1);
  }, []);
  const index = useRotation(body, wall.length, picking || now, nudge);
  const entry = now ? wall[0] : wall[index];
  return (
    <section className="card desktop-prayer-wall" aria-label={t('wall.title')}>
      <WallHeading onMore={onMore} />
      {entry ? (
        <div ref={body} key={entry.id} className="desktop-prayer-body is-entering">
          <PrayerEntry entry={entry} praying={praying.includes(entry.id)} onActivity={onActivity} />
        </div>
      ) : (
        <WallEmpty />
      )}
    </section>
  );
}

export function WallHeading({ onMore }: { onMore: () => void }) {
  const { t } = useTranslation();
  const count = useRadio((s) => s.engine.wall.length);
  return (
    <div className="section-heading">
      <PrayIcon />
      <h2>{t('wall.title')}</h2>
      {count > 0 && (
        <button type="button" className="text-button" onClick={onMore}>
          {t('wall.more')} →
        </button>
      )}
    </div>
  );
}

export function PrayerEntry({ entry, praying = false, onActivity }: { entry: WallEntry; praying?: boolean; onActivity?: (pickerOpen: boolean) => void }) {
  const { i18n, t } = useTranslation();
  const now = useServerNow(60_000);
  const key = dayKey(entry.at, now);
  const day = key ? t(`wall.${key}`) : new Intl.DateTimeFormat(i18n.language, { day: 'numeric', month: 'long' }).format(entry.at);
  return (
    <div className="quote feed-message prayer-entry">
      <div className="message-copy">
        <time dateTime={new Date(entry.at).toISOString()}>{day}</time>
        {praying && <span className="praying-badge">{t('wall.prayingNow')}</span>}
        <p>{entry.text}</p>
      </div>
      <Reactions markId={`voice:${entry.id}`} variant="prayer" onSend={(kind) => reactVoice(entry.id, kind)} onActivity={onActivity} />
    </div>
  );
}

/** Nothing on the wall yet: an invitation to be the first. */
export function WallEmpty() {
  const { t } = useTranslation();
  const show = useSheets((s) => s.show);
  return (
    <div className="feed-empty">
      <p>{t('wall.empty')}</p>
      <button type="button" className="text-button" onClick={() => show('prayer')}>
        {t('wall.share')} →
      </button>
    </div>
  );
}

/** "More →": the whole wall, newest first. */
export function PrayerWallSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const { wall, praying } = usePrayingWall();
  return (
    <BottomSheet open={open} onClose={onClose} title={t('wall.title')}>
      <BottomSheetBody>
        <div className="prayer-wall-list">
          <p className="text-sm text-ink-muted">{t('wall.intro')}</p>
          {wall.length === 0 ? <WallEmpty /> : wall.map((e) => <PrayerEntry key={e.id} entry={e} praying={praying.includes(e.id)} />)}
        </div>
      </BottomSheetBody>
    </BottomSheet>
  );
}
