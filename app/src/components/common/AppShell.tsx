import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { UpdateBanner } from './UpdateBanner';
import { CalendarIcon, ChatIcon, HomeIcon, RadioIcon, ShieldIcon, UserIcon } from './icons';
import { StageRegion } from '@/components/stage/StageRegion';
import { ChannelPicker } from '@/components/home/ChannelPicker';
import { useSession, isModerator } from '@/store/session';

/**
 * Header, the persistent stage, the page, and the bottom nav.
 *
 * The stage (and with it the one YouTube player) lives here, above every
 * page, so music never stops when the listener opens the schedule or the chat
 * — and the player is always on screen while it plays, as YouTube requires.
 * On Home the page renders the big stage itself; elsewhere a compact one sits
 * above the page.
 */
export function AppShell() {
  const { t } = useTranslation();
  const location = useLocation();
  const identity = useSession((s) => s.identity);
  const onHome = location.pathname === '/';
  const onMod = location.pathname.startsWith('/mod');

  return (
    <div className="flex min-h-full flex-col pt-safe px-safe">
      <UpdateBanner />
      <header className="mx-auto flex w-full max-w-7xl items-center gap-4 px-4 pb-2 pt-4 lg:px-6">
        <NavLink to="/" className="min-w-0 shrink-0">
          <div className="flex items-center gap-2">
            <span className="text-2xl font-light tracking-logo text-ink sm:text-3xl">ARCHE</span>
            <RadioIcon size={26} className="text-brand-bright" />
          </div>
          <p className="hidden text-xs text-ink-muted sm:block">{t('app.tagline')}</p>
        </NavLink>
        <nav className="mx-auto hidden items-center gap-1 lg:flex" aria-label="main">
          <TopTab to="/" label={t('nav.home')} icon={<HomeIcon />} end />
          <TopTab to="/schedule" label={t('nav.schedule')} icon={<CalendarIcon />} />
          <TopTab to="/chat" label={t('nav.chat')} icon={<ChatIcon />} />
          <TopTab to="/profile" label={t('nav.profile')} icon={<UserIcon />} />
          {isModerator(identity) && <TopTab to="/mod" label={t('nav.mod')} icon={<ShieldIcon />} />}
        </nav>
        <div className="ml-auto min-w-0">
          <ChannelPicker />
        </div>
      </header>

      {/* One stage for all pages: big on Home (placed by the page), compact above
          the others. Moderation pages get no stage, and nothing plays there. */}
      <div className={clsx('mx-auto w-full max-w-7xl px-4 lg:px-6', (onHome || onMod) && 'hidden')}>
        <div className="sticky top-0 z-30 py-2 lg:max-w-xl">
          {!onHome && !onMod && <StageRegion compact />}
        </div>
      </div>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 pb-28 lg:px-6 lg:pb-10">
        <Outlet />
      </main>

      <nav className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-night-line/30 bg-night-deep/90 pb-safe backdrop-blur-lg lg:hidden" aria-label="main">
        <BottomTab to="/" label={t('nav.home')} icon={<HomeIcon size={24} />} end />
        <BottomTab to="/schedule" label={t('nav.schedule')} icon={<CalendarIcon size={24} />} />
        <BottomTab to="/chat" label={t('nav.chat')} icon={<ChatIcon size={24} />} />
        <BottomTab to="/profile" label={t('nav.profile')} icon={<UserIcon size={24} />} />
      </nav>
    </div>
  );
}

function TopTab({ to, label, icon, end = false }: { to: string; label: string; icon: React.ReactNode; end?: boolean }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        clsx(
          'flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm transition-colors',
          isActive ? 'bg-brand/20 text-ink shadow-glow' : 'text-ink-muted hover:text-ink',
        )
      }
    >
      {icon}
      {label}
    </NavLink>
  );
}

function BottomTab({ to, label, icon, end = false }: { to: string; label: string; icon: React.ReactNode; end?: boolean }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) => clsx('flex flex-col items-center gap-1 py-2.5 text-xs', isActive ? 'text-brand-bright' : 'text-ink-muted')}
    >
      {({ isActive }) => (
        <>
          {icon}
          <span>{label}</span>
          <span className={clsx('h-1 w-1 rounded-full', isActive ? 'bg-brand-bright' : 'bg-transparent')} />
        </>
      )}
    </NavLink>
  );
}
