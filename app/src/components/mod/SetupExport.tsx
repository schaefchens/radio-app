import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { modError } from './modApi';
import { setupFileName } from './setupFile';
import { Notice, Section } from './ui';

/** What GET /mod/export answers (server: Plan\StationSetup), as far as this page reads it. */
interface StationSetup {
  exported: number;
  tables: Record<string, unknown[]>;
}

/**
 * The station's setup as a file, to try the program on a local stack with
 * the real library and hosts (`npm run setup:import`). Admins only, and the
 * server checks it again: the hosts' private style notes travel along. No
 * listener's data, no keys.
 */
export function SetupExport() {
  const { t } = useTranslation();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  const download = async (): Promise<void> => {
    setBusy(true);
    setNotice(null);
    try {
      const setup = await api<StationSetup>('/mod/export');
      const name = setupFileName(setup.exported);
      const url = URL.createObjectURL(new Blob([JSON.stringify(setup)], { type: 'application/json' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = name;
      document.body.append(link);
      link.click();
      link.remove();
      // Revoked at once, some browsers would drop the download before it starts.
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
      const n = (table: string): number => setup.tables[table]?.length ?? 0;
      setNotice({ tone: 'ok', text: t('mod.status.setupDone', { name, programs: n('programs'), items: n('library_items'), hosts: n('hosts') }) });
    } catch (e) {
      setNotice({ tone: 'error', text: modError(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title={t('mod.status.setup')}>
      <p className="text-sm text-ink-muted">{t('mod.status.setupText')}</p>
      <button type="button" className="btn-primary self-start" disabled={busy} onClick={() => void download()}>
        {busy ? t('mod.status.setupBusy') : t('mod.status.setupDownload')}
      </button>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </Section>
  );
}
