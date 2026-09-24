import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ChannelInfo, Lang } from '@arche/shared';
import { useSession } from '@/store/session';
import { useRadio } from '@/store/radio';
import { switchChannel } from '@/lib/radio';
import { ChevronIcon, RadioIcon } from '@/components/common/icons';

// A stable empty list: a selector that returns a fresh [] on every call makes
// the store subscription see a change each render and React bails out.
const NONE: ChannelInfo[] = [];

export function ChannelPicker() {
  const { t, i18n } = useTranslation();
  const lang = (i18n.language === 'de' ? 'de' : 'en') as Lang;
  const channels = useSession((s) => s.channels?.channels ?? NONE);
  const current = useRadio((s) => s.engine.channel);
  const [open, setOpen] = useState(false);
  const active = channels.find((c) => c.id === current);
  if (channels.length === 0) return null;
  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="listbox"
        aria-expanded={open}
        disabled={channels.length < 2}
        aria-label={`${t('channel.label')}: ${active?.name[lang] ?? current}`}
        className="flex min-w-0 max-w-full items-center gap-2 rounded-full border border-night-line/40 bg-night-deep/60 px-3 py-2 text-sm hover:bg-night-raised disabled:cursor-default sm:px-4"
      >
        <RadioIcon size={18} className="shrink-0 text-brand-bright" />
        {/* A phone has room for the channel's name only. */}
        <span className="hidden text-ink-muted sm:inline">{t('channel.label')}:</span>
        <span className="truncate font-semibold">{active?.name[lang] ?? current}</span>
        {channels.length > 1 && <ChevronIcon size={16} />}
      </button>
      {open && (
        <ul role="listbox" className="absolute right-0 z-40 mt-2 min-w-[12rem] overflow-hidden rounded-2xl border border-night-line/40 bg-night-deep/95 p-1 shadow-card backdrop-blur">
          {channels.map((c) => (
            <li key={c.id}>
              <button
                type="button"
                role="option"
                aria-selected={c.id === current}
                onClick={() => {
                  setOpen(false);
                  if (c.id !== current) void switchChannel(c.id);
                }}
                className="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-night-raised aria-selected:bg-brand/20"
              >
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: c.color }} />
                {c.name[lang]}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
