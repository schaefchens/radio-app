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
      className="flex w-full items-center justify-center gap-2 border-b border-brand/30 bg-brand/15 px-4 py-2 text-sm text-brand-bright transition-colors hover:bg-brand/25"
    >
      <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-brand-bright" />
      {t('updates.banner')}
    </button>
  );
}
