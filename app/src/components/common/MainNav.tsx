import { NavLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { CalendarIcon, ChatIcon, ModerationIcon, NavLiveIcon, UserIcon } from './icons';
import { useSession, isModerator } from '@/store/session';

/**
 * The menu: in the scenery on desktop (`desktop-nav`), under the tiles on a
 * tablet and in the phone dock (`navigation`). Moderators get a fifth item,
 * which widens it (`has-moderation`).
 */
export function MainNav({ className }: { className: 'desktop-nav' | 'navigation' }) {
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const mod = isModerator(identity);
  return (
    <nav className={clsx(className, mod && 'has-moderation')} aria-label={t('nav.label')}>
      <NavLink to="/" end className="nav-item nav-live">
        <NavLiveIcon />
        <span>{t('nav.home')}</span>
      </NavLink>
      <NavLink to="/schedule" className="nav-item">
        <CalendarIcon />
        <span>{t('nav.schedule')}</span>
      </NavLink>
      <NavLink to="/chat" className="nav-item">
        <ChatIcon />
        <span>{t('nav.chat')}</span>
      </NavLink>
      <NavLink to="/profile" className="nav-item">
        <UserIcon />
        <span>{t('nav.profile')}</span>
      </NavLink>
      {mod && (
        <NavLink to="/mod" className="nav-item">
          <ModerationIcon />
          <span>{t('nav.mod')}</span>
        </NavLink>
      )}
    </nav>
  );
}
