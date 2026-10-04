import { useTranslation } from 'react-i18next';
import { localDate } from '@/lib/format';
import { useBlocks } from '@/store/blocks';

/** The people this listener blocked in the rooms, to unblock them again (this device only). */
export function BlockedList() {
  const { t, i18n } = useTranslation();
  const users = useBlocks((s) => s.users);
  const unblock = useBlocks((s) => s.unblock);
  if (users.length === 0) return null;
  return (
    <section className="card flex flex-col gap-3 p-4">
      <h2 className="text-lg font-semibold">{t('profile.blocked.title')}</h2>
      <p className="text-sm text-ink-muted">{t('profile.blocked.hint')}</p>
      <ul className="flex flex-col gap-2">
        {users.map((u) => (
          <li key={u.sub} className="flex items-center justify-between gap-3 text-sm">
            <span className="min-w-0 truncate">
              <span className="font-semibold">{u.name || '—'}</span>
              <span className="text-ink-faint"> · {localDate(u.at, i18n.language, { day: 'numeric', month: 'short' })}</span>
            </span>
            <button type="button" className="btn-ghost shrink-0 px-3 py-1.5 text-xs" onClick={() => unblock(u.sub)}>
              {t('profile.blocked.unblock')}
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}
