import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { CdnImg } from '@/components/common/CdnImg';
import { useApi } from './useApi';
import type { LineupEntry, ModHost } from './modApi';
import { Loading, Notice, Pill } from './ui';

/**
 * Who speaks in a program or on a channel (Host\Hosts): on-air hosts — with
 * several, one is picked at random for each show — and fallbacks, in order,
 * for when the on-air host cannot speak (its characters for the day used up,
 * its provider failing). A host of the same name steps in first: the same
 * persona with another voice. Empty: the channel's hosts (a channel's: the
 * main channel's).
 */
export function HostLineup({ value, onChange, emptyHint }: { value: LineupEntry[]; onChange: (v: LineupEntry[]) => void; emptyHint: string }) {
  const { t } = useTranslation();
  const addId = useId();
  const { data, error } = useApi<{ hosts: ModHost[] }>('/mod/hosts');
  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;
  const byId = new Map(data.hosts.map((h) => [h.id, h]));
  // A host the list does not know (deleted in another tab) is left out.
  const entries = value.filter((e) => byId.has(e.id));
  const others = data.hosts.filter((h) => !entries.some((e) => e.id === h.id));
  const mains = entries.filter((e) => e.role === 'main').length;
  const move = (i: number, by: -1 | 1): void => {
    const next = [...entries];
    const [e] = next.splice(i, 1);
    if (!e) return;
    next.splice(i + by, 0, e);
    onChange(next);
  };

  return (
    <div className="flex flex-col gap-2">
      {entries.length === 0 ? (
        <p className="text-sm text-ink-muted">{emptyHint}</p>
      ) : (
        <ul className="flex flex-col gap-1.5">
          {entries.map((e, i) => {
            const h = byId.get(e.id)!;
            return (
              <li key={e.id} className="card-inset flex flex-wrap items-center gap-2 p-2">
                <HostAvatar host={h} size="h-8 w-8" />
                <span className="min-w-0 flex-1 truncate text-sm font-medium">{h.name}</span>
                <Pill>{t(`mod.hosts.providers.${h.provider}`)}</Pill>
                {!h.active && <Pill>{t('mod.common.inactive')}</Pill>}
                <select
                  className="field w-auto py-1 text-sm"
                  value={e.role}
                  aria-label={t('mod.hosts.lineup.role', { name: h.name })}
                  onChange={(ev) => onChange(entries.map((x) => (x.id === e.id ? { ...x, role: ev.target.value as LineupEntry['role'] } : x)))}
                >
                  <option value="main">{t('mod.hosts.lineup.main')}</option>
                  <option value="fallback">{t('mod.hosts.lineup.fallback')}</option>
                </select>
                <button type="button" className="btn-ghost px-2 py-1 text-xs" disabled={i === 0} aria-label={t('mod.hosts.lineup.up', { name: h.name })} onClick={() => move(i, -1)}>
                  ↑
                </button>
                <button
                  type="button"
                  className="btn-ghost px-2 py-1 text-xs"
                  disabled={i === entries.length - 1}
                  aria-label={t('mod.hosts.lineup.down', { name: h.name })}
                  onClick={() => move(i, 1)}
                >
                  ↓
                </button>
                <button type="button" className="btn-ghost px-2 py-1 text-xs" aria-label={t('mod.hosts.lineup.remove', { name: h.name })} onClick={() => onChange(entries.filter((x) => x.id !== e.id))}>
                  ×
                </button>
              </li>
            );
          })}
        </ul>
      )}
      {entries.length > 0 && mains === 0 && <Notice tone="error">{t('mod.hosts.lineup.noMain')}</Notice>}
      {others.length > 0 && entries.length < 8 && (
        <div className="flex flex-wrap items-center gap-2">
          <label htmlFor={addId} className="text-sm text-ink-muted">
            {t('mod.hosts.lineup.add')}
          </label>
          <select
            id={addId}
            className="field w-auto py-1 text-sm"
            value=""
            onChange={(ev) => {
              const id = Number(ev.target.value);
              // The first one goes on air; the next ones start as fallbacks.
              if (id) onChange([...entries, { id, role: mains === 0 ? 'main' : 'fallback' }]);
            }}
          >
            <option value="">{t('mod.common.choose')}</option>
            {others.map((h) => (
              <option key={h.id} value={h.id}>
                {h.name} · {t(`mod.hosts.providers.${h.provider}`)}
              </option>
            ))}
          </select>
        </div>
      )}
      <p className="text-xs text-ink-faint">{t('mod.hosts.lineup.hint')}</p>
    </div>
  );
}

/** A host's picture, or their initial, in a ring of their color. */
export function HostAvatar({ host, size }: { host: Pick<ModHost, 'name' | 'avatar' | 'color'>; size: string }) {
  return host.avatar ? (
    <CdnImg src={host.avatar} className={`${size} shrink-0 rounded-full border-2 object-cover`} style={{ borderColor: host.color }} />
  ) : (
    <span className={`${size} flex shrink-0 items-center justify-center rounded-full border-2 bg-soft text-sm font-semibold`} style={{ borderColor: host.color }}>
      {host.name.slice(0, 1)}
    </span>
  );
}
