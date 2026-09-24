import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, NavLink, Route, Routes } from 'react-router-dom';
import clsx from 'clsx';
import { useSession, isModerator } from '@/store/session';
import { useOverview } from '@/components/mod/overview';
import { StatusPanel } from '@/components/mod/StatusPanel';
import { LibraryPanel } from '@/components/mod/LibraryPanel';
import { ProgramsPanel } from '@/components/mod/ProgramsPanel';
import { PlansPanel } from '@/components/mod/PlansPanel';
import { ReviewPanel } from '@/components/mod/ReviewPanel';
import { ChatModPanel } from '@/components/mod/ChatModPanel';
import { UsersPanel } from '@/components/mod/UsersPanel';
import { ChannelsPanel } from '@/components/mod/ChannelsPanel';
import { Loading, Notice } from '@/components/mod/ui';

/**
 * /mod — the Plan layer and the content pool. Moderators build programs,
 * plans and the library; admins additionally manage users and channels. The
 * server checks every call again: this gate only keeps the UI honest.
 */
export function ModRoutes() {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const loaded = useSession((s) => s.config !== null || s.apiDown);
  const overview = useOverview((s) => s.data);
  const error = useOverview((s) => s.error);
  const allowed = isModerator(identity);
  const admin = allowed && identity?.role === 'admin';

  useEffect(() => {
    if (allowed) void useOverview.getState().load();
  }, [allowed]);

  if (!loaded) return <Loading />;
  if (!allowed) return <Navigate to="/" replace />;

  const tabs: { to: string; label: string; badge?: number; end?: boolean }[] = [
    { to: '/mod', label: t('mod.nav.status'), end: true },
    { to: '/mod/library', label: t('mod.nav.library') },
    { to: '/mod/programs', label: t('mod.nav.programs') },
    { to: '/mod/plans', label: t('mod.nav.plans') },
    { to: '/mod/review', label: t('mod.nav.review'), badge: overview?.review },
    { to: '/mod/chat', label: t('mod.nav.chat'), badge: (overview?.reports ?? 0) + (overview?.highlights ?? 0) },
    ...(admin ? [{ to: '/mod/users', label: t('mod.nav.users') }, { to: '/mod/channels', label: t('mod.nav.channels') }] : []),
  ];

  return (
    <div className="flex flex-col gap-4 pt-2">
      <h1 className="text-2xl font-semibold">{t('mod.title')}</h1>
      <nav className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1" aria-label={t('mod.title')}>
        {tabs.map((tab) => (
          <NavLink
            key={tab.to}
            to={tab.to}
            end={tab.end}
            className={({ isActive }) =>
              clsx('inline-flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-2 text-sm', isActive ? 'bg-brand/20 text-ink shadow-glow' : 'text-ink-muted hover:text-ink')
            }
          >
            {tab.label}
            {!!tab.badge && <span className="rounded-full bg-live px-1.5 text-[0.65rem] font-bold text-white">{tab.badge}</span>}
          </NavLink>
        ))}
      </nav>
      {error && <Notice tone="error">{error}</Notice>}
      <Routes>
        <Route index element={<StatusPanel />} />
        <Route path="library" element={<LibraryPanel />} />
        <Route path="programs" element={<ProgramsPanel />} />
        <Route path="plans" element={<PlansPanel />} />
        <Route path="review" element={<ReviewPanel />} />
        <Route path="chat" element={<ChatModPanel />} />
        {admin && <Route path="users" element={<UsersPanel />} />}
        {admin && <Route path="channels" element={<ChannelsPanel />} />}
        <Route path="*" element={<Navigate to="/mod" replace />} />
      </Routes>
    </div>
  );
}
