import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Lang, Role } from '@arche/shared';
import { api } from '@/lib/api';
import { countryName, localDate } from '@/lib/format';
import { useSession } from '@/store/session';
import { useApi } from './useApi';
import { modError } from './modApi';
import { Check, Loading, Notice, Pill, Section } from './ui';

interface UserRow {
  id: string;
  name: string;
  country: string;
  lang: string;
  role: Role;
  claimed: boolean;
  banned: boolean;
  lastSeen: number;
  /** Devices that share this account through its passphrase. */
  devices: number;
}

const ROLES: Role[] = ['listener', 'moderator', 'admin'];

export function UsersPanel() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const me = useSession((s) => s.identity);
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const { data, error, reload } = useApi<{ users: UserRow[] }>(`/mod/users?q=${encodeURIComponent(query)}`);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  const patch = async (id: string, body: Record<string, unknown>): Promise<void> => {
    try {
      await api(`/mod/users/${id}`, { method: 'PATCH', body });
      setNotice({ tone: 'ok', text: t('mod.common.saved') });
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    }
    reload();
  };

  return (
    <Section
      title={t('mod.nav.users')}
      actions={
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            setQuery(q.trim());
          }}
        >
          <input className="field w-56 py-1.5" placeholder={t('mod.users.search')} value={q} onChange={(e) => setQ(e.target.value)} />
          <button type="submit" className="btn-ghost px-3 py-1.5">
            {t('mod.common.search')}
          </button>
        </form>
      }
    >
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {error && <Notice tone="error">{error}</Notice>}
      {!data ? (
        <Loading />
      ) : data.users.length === 0 ? (
        <p className="text-sm text-ink-muted">{t('mod.users.empty')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {data.users.map((u) => (
            <li key={u.id} className="card-inset flex flex-wrap items-center gap-3 p-3 text-sm">
              <div className="min-w-0 flex-1">
                <p className="font-semibold">
                  {u.name || '—'} <span className="font-mono text-xs text-ink-faint">{u.id}</span>
                </p>
                <p className="text-xs text-ink-muted">
                  {u.country ? countryName(u.country, lang) : '—'} · {t('mod.users.lastSeen')} {localDate(u.lastSeen, lang)}
                </p>
                <div className="mt-1 flex gap-1">
                  {u.claimed && <Pill tone="good">{t('mod.users.claimed')}</Pill>}
                  {u.banned && <Pill tone="bad">{t('mod.users.banned')}</Pill>}
                  {u.devices > 1 && <Pill>{t('mod.users.devices', { count: u.devices })}</Pill>}
                </div>
              </div>
              <select
                className="field w-auto"
                value={u.role}
                aria-label={t('mod.users.role')}
                disabled={u.id === me?.id}
                onChange={(e) => void patch(u.id, { role: e.target.value })}
              >
                {ROLES.map((r) => (
                  <option key={r} value={r} disabled={r !== 'listener' && !u.claimed}>
                    {t(`mod.users.roles.${r}`)}
                  </option>
                ))}
              </select>
              <Check label={t('mod.users.banned')} checked={u.banned} disabled={u.role === 'admin'} onChange={(v) => void patch(u.id, { banned: v })} />
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}
