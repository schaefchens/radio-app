import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useApi } from './useApi';
import { modError, type GroupLink, type LibraryGroup } from './modApi';
import { Check, ConfirmButton, Field, Loading, Notice, Pill, Section } from './ui';

const LINK_KINDS: GroupLink['kind'][] = ['youtube', 'website', 'other'];
const MAX_LINKS = 4;

/** What the form edits and sends: the group without its id and count. */
type Draft = Omit<LibraryGroup, 'id' | 'items'>;

const draftOf = (g: LibraryGroup | null): Draft => ({
  name: g?.name ?? '',
  about_en: g?.about_en ?? '',
  about_de: g?.about_de ?? '',
  links: g?.links ?? [],
  channels: g?.channels ?? [],
  names: g?.names ?? [],
  notice: g?.notice ?? 0,
  blocked: g?.blocked ?? 0,
  note: g?.note ?? '',
});

/**
 * /mod › Groups: a preacher, a church, a ministry or an artist whose videos
 * belong together. With "notice", after one of them the host points to more
 * from them while the stage shows these links; "not on our platform" is for
 * those who asked to be left out — nothing of theirs is accepted or played.
 * Their videos join by their YouTube channel; for blocking also by the names
 * their titles carry, which catches re-uploads on other channels.
 */
export function GroupsPanel() {
  const { t } = useTranslation();
  const { data, error, reload } = useApi<{ groups: LibraryGroup[] }>('/mod/groups');
  const [editing, setEditing] = useState<LibraryGroup | 'new' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Loading />;
  const saved = (text: string): void => {
    setEditing(null);
    setNotice(text);
    reload();
  };

  return (
    <div className="flex flex-col gap-4">
      {notice && <Notice tone="ok">{notice}</Notice>}
      {editing !== null ? (
        <GroupEditor key={editing === 'new' ? 'new' : editing.id} group={editing === 'new' ? null : editing} onSaved={saved} onCancel={() => setEditing(null)} />
      ) : (
        <Section
          title={t('mod.nav.groups')}
          actions={
            <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setEditing('new')}>
              {t('mod.groups.new')}
            </button>
          }
        >
          <p className="text-sm text-ink-muted">{t('mod.groups.intro')}</p>
          {data.groups.length === 0 ? (
            <p className="text-sm text-ink-muted">{t('mod.groups.none')}</p>
          ) : (
            <ul className="flex flex-col gap-2">
              {data.groups.map((g) => (
                <li key={g.id} className="card-inset flex flex-wrap items-center gap-2 p-3">
                  <span className="min-w-0 flex-1 truncate font-medium">{g.name}</span>
                  {g.notice === 1 && <Pill tone="good">{t('mod.groups.badgeNotice')}</Pill>}
                  {g.blocked === 1 && <Pill tone="bad">{t('mod.groups.badgeBlocked')}</Pill>}
                  <Pill>{t('mod.groups.items', { count: g.items })}</Pill>
                  <button type="button" className="btn-ghost px-3 py-1.5" onClick={() => setEditing(g)}>
                    {t('mod.common.edit')}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}
    </div>
  );
}

function GroupEditor({ group, onSaved, onCancel }: { group: LibraryGroup | null; onSaved: (text: string) => void; onCancel: () => void }) {
  const { t } = useTranslation();
  const [d, setD] = useState<Draft>(() => draftOf(group));
  const [names, setNames] = useState(() => (group?.names ?? []).join(', '));
  const [channelUrl, setChannelUrl] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = <K extends keyof Draft>(k: K, v: Draft[K]): void => setD((x) => ({ ...x, [k]: v }));
  const setLink = (i: number, patch: Partial<GroupLink>): void => set('links', d.links.map((l, j) => (j === i ? { ...l, ...patch } : l)));

  const run = async (fn: () => Promise<unknown>, done: string): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      onSaved(done);
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };
  const save = (): Promise<void> =>
    run(() => {
      const body = { ...d, names: names.split(',').map((n) => n.trim()).filter(Boolean), links: d.links.filter((l) => l.url.trim() !== '') };
      return group ? api(`/mod/groups/${group.id}`, { method: 'PATCH', body }) : api('/mod/groups', { body });
    }, t('mod.groups.saved'));
  const addChannel = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const { channel } = await api<{ channel: { id: string; title: string } }>('/mod/groups/channel', { body: { url: channelUrl } });
      if (!d.channels.some((c) => c.id === channel.id)) set('channels', [...d.channels, channel]);
      setChannelUrl('');
    } catch (e) {
      setError(modError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title={group ? t('mod.groups.edit', { name: group.name }) : t('mod.groups.new')}>
      <div className="flex flex-col gap-3">
        <Field label={t('mod.groups.name')}>
          <input className="field" maxLength={60} value={d.name} onChange={(e) => set('name', e.target.value)} />
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('mod.groups.aboutEn')} hint={t('mod.groups.aboutHint')}>
            <textarea className="field min-h-[64px]" maxLength={200} value={d.about_en} onChange={(e) => set('about_en', e.target.value)} />
          </Field>
          <Field label={t('mod.groups.aboutDe')}>
            <textarea className="field min-h-[64px]" maxLength={200} value={d.about_de} onChange={(e) => set('about_de', e.target.value)} />
          </Field>
        </div>

        <div className="flex flex-col gap-2">
          <p className="label">{t('mod.groups.links')}</p>
          {d.links.map((l, i) => (
            <div key={i} className="flex flex-wrap gap-2">
              <select className="field w-auto" value={l.kind} aria-label={t('mod.groups.linkKind')} onChange={(e) => setLink(i, { kind: e.target.value as GroupLink['kind'] })}>
                {LINK_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {t(`mod.groups.linkKinds.${k}`)}
                  </option>
                ))}
              </select>
              <input className="field min-w-0 flex-1" inputMode="url" placeholder="https://…" value={l.url} aria-label={t('mod.groups.linkUrl')} onChange={(e) => setLink(i, { url: e.target.value })} />
              <button type="button" className="btn-ghost px-3" onClick={() => set('links', d.links.filter((_, j) => j !== i))}>
                {t('mod.groups.remove')}
              </button>
            </div>
          ))}
          {d.links.length < MAX_LINKS && (
            <button type="button" className="btn-ghost self-start px-3 py-1.5" onClick={() => set('links', [...d.links, { kind: d.links.length ? 'website' : 'youtube', url: '' }])}>
              {t('mod.groups.addLink')}
            </button>
          )}
          <p className="text-xs text-ink-faint">{t('mod.groups.linksHint')}</p>
        </div>

        <div className="flex flex-col gap-2">
          <p className="label">{t('mod.groups.channels')}</p>
          {d.channels.length > 0 && (
            <div className="flex flex-wrap gap-1">
              {d.channels.map((c) => (
                <span key={c.id} className="inline-flex items-center gap-1 rounded-full bg-accent-fill/15 px-2 py-0.5 text-xs text-accent">
                  {c.title || c.id}
                  <button type="button" className="text-ink-muted hover:text-ink" aria-label={t('mod.groups.remove')} onClick={() => set('channels', d.channels.filter((x) => x.id !== c.id))}>
                    ×
                  </button>
                </span>
              ))}
            </div>
          )}
          <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              void addChannel();
            }}
          >
            <input className="field min-w-0 flex-1" inputMode="url" placeholder="https://youtu.be/…" value={channelUrl} aria-label={t('mod.groups.channelUrl')} onChange={(e) => setChannelUrl(e.target.value)} />
            <button type="submit" className="btn-ghost px-3" disabled={busy || channelUrl.trim() === ''}>
              {t('mod.groups.addChannel')}
            </button>
          </form>
          <p className="text-xs text-ink-faint">{t('mod.groups.channelsHint')}</p>
        </div>

        <Check label={t('mod.groups.notice')} checked={d.notice === 1} onChange={(v) => set('notice', v ? 1 : 0)} />
        <Check label={t('mod.groups.blocked')} checked={d.blocked === 1} onChange={(v) => set('blocked', v ? 1 : 0)} />
        {d.blocked === 1 && (
          <>
            <Field label={t('mod.groups.names')} hint={t('mod.groups.namesHint')}>
              <input className="field" value={names} placeholder={d.name} onChange={(e) => setNames(e.target.value)} />
            </Field>
            <Field label={t('mod.groups.note')}>
              <textarea className="field min-h-[56px]" maxLength={500} value={d.note} onChange={(e) => set('note', e.target.value)} />
            </Field>
          </>
        )}

        {error && <Notice tone="error">{error}</Notice>}
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn-primary" disabled={busy || d.name.trim() === ''} onClick={() => void save()}>
            {t('mod.common.save')}
          </button>
          <button type="button" className="btn-ghost" onClick={onCancel}>
            {t('mod.common.cancel')}
          </button>
          {group && (
            <ConfirmButton
              label={t('mod.groups.delete')}
              question={t('mod.groups.deleteConfirm')}
              disabled={busy}
              onConfirm={() => void run(() => api(`/mod/groups/${group.id}`, { method: 'DELETE' }), t('mod.groups.deleted'))}
            />
          )}
        </div>
      </div>
    </Section>
  );
}
