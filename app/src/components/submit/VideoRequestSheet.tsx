import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { VIDEO_FORMATS, VIDEO_SUBMISSIONS, isVideoFormat, type SubmissionState, type VideoFormat } from '@arche/shared';
import { BottomSheet, BottomSheetBody } from '@/components/common/BottomSheet';
import { api } from '@/lib/api';
import { useSession } from '@/store/session';
import { useSettings } from '@/store/settings';
import { useRadio } from '@/store/radio';
import { useSubmit } from './useSubmit';
import { parseYouTubeId } from '@/lib/youtubeUrl';
import { CheckIcon } from '@/components/common/icons';
import { RulesCheckbox } from '@/components/common/RulesConsent';
import { acceptRules, useRulesNeeded } from '@/lib/rulesConsent';

interface Preview {
  /** The video id this preview belongs to; a stale one is simply not shown. */
  id: string;
  title: string;
  author: string;
  thumb: string;
  error: string | null;
}

/** What a listener hands in as a YouTube link: a song request, or a video suggested for a video program. */
export type RequestKind = 'song' | 'video';

const taken = (s: SubmissionState | undefined): boolean => s === 'open' || s === 'closing';

/**
 * A song request, or a video suggested for a program of preachings,
 * testimonies, mission videos or films: the same form — a YouTube link, a word
 * for the host, name and place or "Stay anonymous" — with its own texts and
 * endpoint. The video form first asks which kind, like the recording sheet:
 * only the kinds the minute file lists as open can be picked.
 */
export function VideoRequestSheet({ kind, open, onClose }: { kind: RequestKind; open: boolean; onClose: () => void }) {
  const uid = useId();
  const { t } = useTranslation();
  const texts = kind === 'song' ? 'songForm' : 'videoForm';
  const identity = useSession((s) => s.identity);
  const consent = useSettings((s) => s.consent);
  const lang = useSettings((s) => s.lang);
  const channel = useRadio((s) => s.engine.channel);
  const submissions = useRadio((s) => s.engine.submissions);
  const format = useRadio((s) => s.engine.program?.format);
  // The kind the listener picked, or was shown when they began pasting a
  // link: it is never switched under them — a sermon link silently turned
  // into a mission suggestion would go through the wrong check.
  const [chosen, setChosen] = useState<VideoFormat | null>(null);
  const takes = (f: VideoFormat): boolean => taken(submissions[VIDEO_SUBMISSIONS[f]]);
  const own = isVideoFormat(format) ? format : null;
  // Untouched, the form follows the program: its own kind when that is open,
  // else the first open one (a mission program that also takes testimonies
  // opens on "Mission", not on the kind that comes first in the list).
  const video: VideoFormat = chosen ?? (own && takes(own) ? own : (VIDEO_FORMATS.find(takes) ?? own ?? 'preaching'));
  const videoOpen = kind === 'song' || takes(video);
  const [url, setUrl] = useState('');
  const [message, setMessage] = useState('');
  const [name, setName] = useState(identity?.name ?? '');
  const [place, setPlace] = useState('');
  // Kept for the next request: one who chose to stay anonymous is not named by surprise.
  const [anonymous, setAnonymous] = useState(false);
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

  const submit = useSubmit(() =>
    api(`/submissions/${kind}`, {
      body: { ...(kind === 'video' ? { type: VIDEO_SUBMISSIONS[video] } : {}), channel, url, message, name: anonymous ? '' : name, place: anonymous ? '' : place, lang },
    }),
  );
  // The community rules, once per device before the first post.
  const rulesNeeded = useRulesNeeded();
  const [rulesTicked, setRulesTicked] = useState(false);
  const close = (): void => {
    onClose();
    // A kind chosen for a program that has moved on is not kept for the next one.
    if (chosen !== null && !takes(chosen)) setChosen(null);
    if (submit.done) {
      setUrl('');
      setMessage('');
      setChosen(null);
      submit.reset();
    }
  };

  return (
    <BottomSheet open={open} onClose={close} title={t(`submit.${kind}.title`)}>
      <BottomSheetBody>
        {submit.done ? (
          <Done onClose={close} />
        ) : (
          <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
              e.preventDefault();
              if (rulesNeeded) acceptRules();
              void submit.run();
            }}
          >
            {kind === 'video' && (
              <div>
                <p className="label" id={`${uid}-kind`}>
                  {t('videoForm.kind')}
                </p>
                <div className="grid grid-cols-2 gap-2" role="group" aria-labelledby={`${uid}-kind`}>
                  {VIDEO_FORMATS.map((f) => (
                    <button
                      key={f}
                      type="button"
                      aria-pressed={video === f}
                      disabled={!takes(f) || submit.busy}
                      onClick={() => {
                        setChosen(f);
                        // An error from the kind before ("closed") is not this one's.
                        if (submit.error) submit.reset();
                      }}
                      className={clsx(
                        'min-w-0 break-words rounded-xl border px-3 py-2 text-sm',
                        video === f ? 'border-accent-fill bg-accent-fill/20 text-ink' : 'border-line/30 text-ink-muted',
                        !takes(f) && 'opacity-40',
                      )}
                    >
                      {t(`videoForm.kinds.${f}`)}
                    </button>
                  ))}
                </div>
                {/* The kind in use closed while the listener filled the form in: it stays, and Send waits. */}
                <p className="mt-1 text-xs text-heart" aria-live="polite">
                  {videoOpen ? '' : submissions[VIDEO_SUBMISSIONS[video]] === 'closed' ? t('submit.closed') : t('submit.notNow')}
                </p>
              </div>
            )}
            <div>
              <label className="label" htmlFor={`${uid}-url`}>{t(`${texts}.url`)}</label>
              <input
                id={`${uid}-url`}
                className="field"
                inputMode="url"
                autoComplete="off"
                placeholder="https://youtu.be/…"
                value={url}
                onChange={(e) => {
                  setUrl(e.target.value);
                  // Pasting a link makes the kind shown the listener's choice.
                  if (kind === 'video' && chosen === null) setChosen(video);
                }}
              />
              <p className="mt-1 text-xs text-ink-faint">
                {url && !id ? t('songForm.invalid') : kind === 'song' ? t('songForm.urlHint') : t(`videoForm.hints.${video}`)}
              </p>
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
              <label className="label" htmlFor={`${uid}-msg`}>{t(`${texts}.message`)}</label>
              <textarea id={`${uid}-msg`} className="field min-h-[80px]" maxLength={200} value={message} onChange={(e) => setMessage(e.target.value)} />
              <p className="mt-1 text-xs text-ink-faint">{t(`${texts}.messageHint`)}</p>
            </div>
            <NameOrAnonymous
              anonymous={anonymous}
              setAnonymous={setAnonymous}
              label={t('submit.anonymous')}
              hint={t(`${texts}.nameHint`)}
              name={name}
              place={place}
              setName={setName}
              setPlace={setPlace}
            />
            {submit.error && <p className="text-sm text-heart">{submit.error}</p>}
            {rulesNeeded && <RulesCheckbox checked={rulesTicked} onChange={setRulesTicked} />}
            <PrivacyNote />
            <button
              type="submit"
              className="btn-primary"
              disabled={!id || !!previewError || !videoOpen || (!anonymous && name.trim() === '') || submit.busy || (rulesNeeded && !rulesTicked)}
            >
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

/**
 * Every submission form: "Stay anonymous", and while it is not ticked, first
 * name (needed) and place with what happens to them. Named is the station's
 * default; staying anonymous is a choice the sender makes here, not an empty
 * field they may overlook — the sheet then sends neither.
 */
export function NameOrAnonymous({
  anonymous,
  setAnonymous,
  label,
  hint,
  ...fields
}: {
  anonymous: boolean;
  setAnonymous: (v: boolean) => void;
  label: string;
  hint: string;
  name: string;
  place: string;
  setName: (v: string) => void;
  setPlace: (v: string) => void;
}) {
  return (
    <div className="flex flex-col gap-3">
      <label className="flex items-start gap-2 text-sm text-ink-muted">
        <input type="checkbox" className="mt-1" checked={anonymous} onChange={(e) => setAnonymous(e.target.checked)} />
        {label}
      </label>
      {!anonymous && (
        <div>
          <NamePlace {...fields} />
          <p className="mt-1 text-xs text-ink-faint">{hint}</p>
        </div>
      )}
    </div>
  );
}

/**
 * What sending means, right above the button: submissions can reveal faith or
 * health (Art. 9 GDPR), so the consent has to be explicit and informed.
 */
export function PrivacyNote() {
  const { t } = useTranslation();
  return (
    <p className="text-xs leading-snug text-ink-faint">
      {t('submit.privacyNote')}{' '}
      <Link to="/datenschutz" className="underline">
        {t('submit.privacyLink')}
      </Link>
    </p>
  );
}

export function Done({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col items-center gap-4 py-6 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-accent-fill/20 text-accent">
        <CheckIcon size={28} />
      </div>
      <p className="text-ink">{t('submit.sent')}</p>
      <button type="button" className="btn-ghost" onClick={onClose}>
        {t('common.close')}
      </button>
    </div>
  );
}
