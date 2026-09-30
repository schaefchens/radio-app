import { useTranslation } from 'react-i18next';
import { applyUpdate, useUpdateStore } from '@/lib/pwaUpdate';

export function UpdateBanner() {
  const { t } = useTranslation();
  const needRefresh = useUpdateStore((s) => s.needRefresh);
  if (!needRefresh) return null;
  return (
    <button
      type="button"
      onClick={() => void applyUpdate()}
      className="flex w-full items-center justify-center gap-2 border-b border-accent-fill/30 bg-accent-fill/15 px-4 py-2 text-sm text-accent transition-colors hover:bg-accent-fill/25"
    >
      <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-accent" />
      {t('updates.banner')}
    </button>
  );
}
