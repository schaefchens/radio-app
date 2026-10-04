import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { shareStation } from '@/lib/share';
import { ShareIcon } from './icons';

export function ShareStationButton() {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  return (
    <div className="flex flex-wrap items-center gap-3">
      <button type="button" className="btn-ghost" onClick={() => void shareStation().then((r) => setCopied(r === 'copied'))}>
        <ShareIcon size={16} />
        {t('share.button')}
      </button>
      {copied && (
        <span className="text-xs text-ink-muted" role="status">
          {t('share.copied')}
        </span>
      )}
    </div>
  );
}
