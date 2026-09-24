import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { parseYouTubeId } from '@/lib/youtubeUrl';
import { CheckIcon } from '@/components/common/icons';

interface Preview {
  /** The video id this preview belongs to; a stale one is simply not shown. */
  id: string;
  title: string;
  author: string;
  thumb: string;
  error: string | null;
}

export function SongRequestSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const uid = useId();
  const { t } = useTranslation();
  const identity = useSession((s) => s.identity);
  const consent = useSettings((s) => s.consent);
  const lang = useSettings((s) => s.lang);
  const channel = useRadio((s) => s.engine.channel);
  const [url, setUrl] = useState('');
  const [message, setMessage] = useState('');
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  const [fetched, setFetched] = useState<Preview | null>(null);
  const id = parseYouTubeId(url);
  const current = fetched && fetched.id === id ? fetched : null;
  const preview = current && !current.error ? current : null;
  const previewError = current?.error ?? null;

  // oEmbed preview (title, thumbnail) — only after the listener agreed to
  // YouTube. It cannot tell the duration; the server checks that itself.
  useEffect(() => {
    if (!id || !consent) return;
    const ctl = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const r = await fetch(`https://www.youtube.com/oembed?format=json&url=${encodeURIComponent(`https://www.youtube.com/watch?v=${id}`)}`, { signal: ctl.signal });
        if (r.status === 401 || r.status === 403) return setFetched({ id, title: '', author: '', thumb: '', error: t('songForm.notEmbeddable') });
        if (!r.ok) return setFetched({ id, title: '', author: '', thumb: '', error: t('songForm.invalid') });
        const d = (await r.json()) as { title?: string; author_name?: string; thumbnail_url?: string };
        setFetched({ id, title: d.title ?? '', author: d.author_name ?? '', thumb: d.thumbnail_url ?? '', error: null });
      } catch {
        /* offline or aborted: the server still checks */
      }
    }, 400);
    return () => {
      ctl.abort();
      clearTimeout(timer);
    };
  }, [id, consent, t]);

  const submit = useSubmit(() => api('/submissions/song', { body: { channel, url, message, name, place, lang } }));
  const close = (): void => {
    onClose();
    if (submit.done) {
      setUrl('');
      setMessage('');
      submit.reset();
    }
  };

  return (
    <BottomSheet open={open} onClose={close} title={t('submit.song.title')}>
      <BottomSheetBody>
        {submit.done ? (
          <Done onClose={close} />
        ) : (
          <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
              e.preventDefault();
              void submit.run();
            }}
          >
            <div>
              <label className="label" htmlFor={`${uid}-url`}>{t('songForm.url')}</label>
              <input id={`${uid}-url`} className="field" inputMode="url" autoComplete="off" placeholder="https://youtu.be/…" value={url} onChange={(e) => setUrl(e.target.value)} />
              <p className="mt-1 text-xs text-ink-faint">{url && !id ? t('songForm.invalid') : t('songForm.urlHint')}</p>
            </div>
            {preview && (
              <div className="card-inset flex items-center gap-3 p-2">
                {preview.thumb && <img src={preview.thumb} alt="" className="h-12 w-20 rounded-lg object-cover" />}
                <div className="min-w-0 text-sm">
                  <p className="truncate font-semibold">{preview.title}</p>
                  <p className="truncate text-ink-muted">{preview.author}</p>
                </div>
              </div>
            )}
            {previewError && <p className="text-sm text-heart">{previewError}</p>}
            <div>
              <label className="label" htmlFor={`${uid}-msg`}>{t('songForm.message')}</label>
              <textarea id={`${uid}-msg`} className="field min-h-[80px]" maxLength={200} value={message} onChange={(e) => setMessage(e.target.value)} />
              <p className="mt-1 text-xs text-ink-faint">{t('songForm.messageHint')}</p>
            </div>
            <NamePlace name={name} place={place} setName={setName} setPlace={setPlace} />
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            <button type="submit" className="btn-primary" disabled={!id || !!previewError || submit.busy}>
              {submit.busy ? t('common.loading') : t('submit.send')}
            </button>
          </form>
        )}
      </BottomSheetBody>
    </BottomSheet>
  );
}

export function NamePlace({ name, place, setName, setPlace }: { name: string; place: string; setName: (v: string) => void; setPlace: (v: string) => void }) {
  const { t } = useTranslation();
  // Every sheet has these fields and closed sheets stay mounted: ids must be unique.
  const uid = useId();
  return (
    <div className="grid grid-cols-2 gap-3">
      <div>
        <label className="label" htmlFor={`${uid}-name`}>{t('submit.name')}</label>
        <input id={`${uid}-name`} className="field" maxLength={30} autoComplete="given-name" value={name} onChange={(e) => setName(e.target.value)} />
      </div>
      <div>
        <label className="label" htmlFor={`${uid}-place`}>{t('submit.place')}</label>
        <input id={`${uid}-place`} className="field" maxLength={40} value={place} onChange={(e) => setPlace(e.target.value)} />
      </div>
    </div>
  );
}

export function Done({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col items-center gap-4 py-6 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-brand/20 text-brand-bright">
        <CheckIcon size={28} />
      </div>
      <p className="text-ink">{t('submit.sent')}</p>
      <button type="button" className="btn-ghost" onClick={onClose}>
        {t('common.close')}
      </button>
    </div>
  );
}
