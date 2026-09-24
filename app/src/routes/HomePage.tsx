import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { StageRegion } from '@/components/stage/StageRegion';
import { LiveBar } from '@/components/home/LiveBar';
import { NowPlaying } from '@/components/home/NowPlaying';
import { HostCard } from '@/components/home/HostCard';
import { CommunityVoices } from '@/components/home/CommunityVoices';
import { SubmitButtons, SubmitSheets } from '@/components/home/SubmitButtons';
import { useSubmitSheets } from '@/components/home/useSubmitSheets';
import { DayBlocks } from '@/components/home/TodayProgram';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { ChevronIcon } from '@/components/common/icons';
import { useRadio } from '@/store/radio';
import { useChatVoices } from '@/components/chat/useChatVoices';

/**
 * The main screen of the mockups. Desktop: stage, live bar and now playing on
 * the left, the host and the community voices on the right, the four tiles
 * across, "Today's Program" as a tab at the bottom. Phone: one column, the
 * tiles and the program handle in a panel above the bottom nav.
 */
export function HomePage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const today = useRadio((s) => s.today);
  const chatVoices = useChatVoices();
  const [todayOpen, setTodayOpen] = useState(false);
  const sheets = useSubmitSheets();

  return (
    <div className="flex flex-col gap-4 pt-2">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div className="flex flex-col gap-3">
          <div className="sticky top-0 z-30 -mx-4 bg-night/80 px-4 py-2 backdrop-blur lg:static lg:mx-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-none">
            <StageRegion />
          </div>
          <LiveBar />
          <NowPlaying />
        </div>
        <div className="flex flex-col gap-4">
          <HostCard />
          <CommunityVoices voices={chatVoices} />
        </div>
      </div>

      <div className="hidden lg:block">
        <SubmitButtons variant="wide" onOpen={sheets.show} />
      </div>

      <div className="card flex flex-col gap-3 p-3 lg:hidden">
        <button type="button" onClick={() => setTodayOpen(true)} className="flex flex-col items-center gap-0.5 text-center">
          <span className="flex items-center gap-2 font-semibold">
            <ChevronIcon dir="up" size={18} />
            {t('today.title')}
          </span>
          <span className="text-xs text-ink-muted">{t('today.swipe')}</span>
        </button>
        <SubmitButtons variant="grid" onOpen={sheets.show} />
      </div>

      <button
        type="button"
        onClick={() => setTodayOpen(true)}
        className="mx-auto hidden flex-col items-center rounded-t-3xl border border-b-0 border-night-line/40 bg-night-deep/70 px-12 py-3 text-center lg:flex"
      >
        <ChevronIcon dir="up" size={16} />
        <span className="font-semibold">{t('today.title')}</span>
        <span className="text-xs text-ink-muted">{t('today.open')}</span>
      </button>

      <SubmitSheets state={sheets} />

      <BottomSheet open={todayOpen} onClose={() => setTodayOpen(false)} title={t('today.title')}>
        <BottomSheetBody>
          <DayBlocks day={today} />
          <button
            type="button"
            className="btn-ghost mt-4 w-full"
            onClick={() => {
              setTodayOpen(false);
              navigate('/schedule');
            }}
          >
            {t('today.open')}
          </button>
        </BottomSheetBody>
      </BottomSheet>
    </div>
  );
}
