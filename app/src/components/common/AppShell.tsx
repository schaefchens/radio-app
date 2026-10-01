import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { UpdateBanner } from './UpdateBanner';
import { MainNav } from './MainNav';
import { MobileDock } from './MobileDock';
import { WelcomeDialog } from './WelcomeDialog';
import { InstallSheet } from './InstallSheet';
import { BroadcastIcon } from './icons';
import { StageRegion } from '@/components/stage/StageRegion';
import { SubmitSheets, SubmitTiles } from '@/components/home/SubmitTiles';
import { THEME, useTheme } from '@/lib/theme';
import logo from '@/assets/theme/light-logo.svg';

/**
 * The design's frame (concept-files/theme-preview.html): the scenery with
 * the brand and, on desktop, the menu on top; the page; the scene below
 * with the four tiles; on phones a dock with the tiles and the menu.
 *
 * The stage (and with it the one YouTube player) lives here, above every
 * page, so music never stops when the listener opens the schedule or the chat
 * — and the player is always on screen while it plays, as YouTube requires.
 * On Home the page renders the big stage itself; elsewhere a compact one sits
 * above the page. Moderation pages get no stage, and nothing plays there.
 */
export function AppShell() {
  const location = useLocation();
  const theme = useTheme();
  const onHome = location.pathname === '/';
  const onMod = location.pathname.startsWith('/mod');
  const art = THEME[theme].art;

  return (
    // data-dock tells the phone layout how far the pinned player reaches into
    // the scenery (styles/shell.css), so the header keeps just a strip.
    <div className="page" data-dock={onHome ? 'player' : onMod ? 'none' : 'stage'}>
      <UpdateBanner />
      <header className="hero">
        <img className="hero-art" src={art.top} width={2172} height={724} alt="" />
        <Brand />
        <MainNav className="desktop-nav" />
      </header>

      {!onHome && !onMod && (
        <div className="stage-dock">
          <StageRegion compact />
        </div>
      )}

      <main className={onHome ? 'content' : 'page-content'}>
        <Outlet />
      </main>

      <footer className="lower-scene">
        <img className="bottom-art" src={art.bottom} width={2172} height={724} alt="" loading="lazy" />
        <div className="lower-content">
          {onHome && <SubmitTiles />}
          <MainNav className="navigation" />
        </div>
      </footer>
      {/* Phones: the lower scenery stays put behind the page. */}
      <img className="mobile-art" src={art.bottomMobile} alt="" />
      <MobileDock withTiles={onHome} />

      {/* Mounted once, where the tiles are (a closed sheet stays in the DOM). */}
      {onHome && <SubmitSheets />}
      <WelcomeDialog />
      <InstallSheet />
    </div>
  );
}

/** The station's name over the scenery, linking to the station page. */
function Brand() {
  const { t } = useTranslation();
  return (
    <NavLink to="/about" className="brand" aria-label={t('about.open')} title={t('about.open')}>
      <BroadcastIcon className="brand-mark" />
      <span className="brand-title">
        Arche <span>Radio</span>
      </span>
      {/* The light theme draws the name as the rainbow arc. */}
      <img className="brand-title-arc" src={logo} alt="" />
      <span className="brand-subtitle">{t('brand.subtitle')}</span>
    </NavLink>
  );
}
