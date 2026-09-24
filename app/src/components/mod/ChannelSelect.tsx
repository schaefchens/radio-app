import { useTranslation } from 'react-i18next';
import { useOverview, useModChannelId } from './overview';

export function ChannelSelect() {
  const { t, i18n } = useTranslation();
  const channels = useOverview((s) => s.data?.channels ?? []);
  const setChannel = useOverview((s) => s.setChannel);
  const id = useModChannelId();
  if (channels.length < 2) return null;
  return (
    <label className="flex items-center gap-2 text-sm">
      <span className="text-ink-muted">{t('mod.common.channel')}</span>
      <select className="field w-auto py-1.5" value={id ?? ''} onChange={(e) => setChannel(Number(e.target.value))}>
        {channels.map((c) => (
          <option key={c.id} value={c.id}>
            {i18n.language === 'de' ? c.name_de : c.name_en}
          </option>
        ))}
      </select>
    </label>
  );
}
