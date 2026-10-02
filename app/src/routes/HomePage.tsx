import { useState, useSyncExternalStore } from 'react';
import { PlayerCard } from '@/components/home/PlayerCard';
import { HostCard } from '@/components/home/HostCard';
import { CommunityVoices } from '@/components/home/CommunityVoices';
import { DesktopPrayerWall, PrayerWallSheet } from '@/components/home/PrayerWall';
import { MobileCarousel } from '@/components/home/MobileCarousel';

const PHONE = '(max-width: 600px)';

function subscribePhone(onChange: () => void): () => void {
  const query = matchMedia(PHONE);
  query.addEventListener('change', onChange);
  return () => query.removeEventListener('change', onChange);
}

/**
 * The design's live page (theme-preview.html). Desktop: the player, beside it
 * the host, the community voices and the prayer wall. Tablet: one column, the
 * prayer wall under the player. Phone: the player pinned under the scenery,
 * and one carousel for voices, host and prayer wall. The four tiles are the
 * shell's.
 *
 * Only one layout is mounted, so the feeds rotate (and react) once.
 */
export function HomePage() {
  const phone = useSyncExternalStore(subscribePhone, () => matchMedia(PHONE).matches, () => false);
  const [wallOpen, setWallOpen] = useState(false);
  const openWall = () => setWallOpen(true);
  return (
    <>
      <div className="player-dock">
        <PlayerCard />
      </div>
      {phone ? (
        <MobileCarousel onMoreWall={openWall} />
      ) : (
        <>
          {/* Before the host, so the tablet's one column keeps it under the player. */}
          <DesktopPrayerWall onMore={openWall} />
          <HostCard />
          <CommunityVoices />
        </>
      )}
      <PrayerWallSheet open={wallOpen} onClose={() => setWallOpen(false)} />
    </>
  );
}
