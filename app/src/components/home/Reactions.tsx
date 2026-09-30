import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ReactionKind } from '@arche/shared';
import { NO_MARK, useReactions } from '@/store/reactions';
import { HeartIcon, PrayIcon, SmileIcon } from '@/components/common/icons';

interface Emoji {
  emoji: string;
  kind: ReactionKind;
  /** i18n key of its name: the same kind may wear another face in the feed. */
  label: string;
}

/** Behind the smile on the song: the design's eight. */
const SONG_EMOJI: Emoji[] = [
  { emoji: '🙌', kind: 'raise', label: 'emoji.hallelujah' },
  { emoji: '😊', kind: 'smile', label: 'emoji.joy' },
  { emoji: '😍', kind: 'love', label: 'emoji.excited' },
  { emoji: '🥹', kind: 'moved', label: 'emoji.moved' },
  { emoji: '🕊️', kind: 'peace', label: 'emoji.peace' },
  { emoji: '✨', kind: 'hope', label: 'emoji.hope' },
  { emoji: '🎉', kind: 'celebrate', label: 'emoji.celebrate' },
  { emoji: '🔥', kind: 'fire', label: 'emoji.onFire' },
];
/** Behind the smile on a voice or a prayer: the design's five. */
const FEED_EMOJI: Emoji[] = [
  { emoji: '🙌', kind: 'raise', label: 'emoji.cheer' },
  { emoji: '😊', kind: 'smile', label: 'emoji.joy' },
  { emoji: '🕊️', kind: 'peace', label: 'emoji.peace' },
  { emoji: '✨', kind: 'hope', label: 'emoji.thanks' },
  { emoji: '❤️', kind: 'love', label: 'emoji.love' },
];

interface Props {
  /** The mark's key: what this device pressed stays pressed. */
  markId: string;
  variant: 'song' | 'feed' | 'prayer';
  /** Called once per kind, the first time it is pressed. */
  onSend: (kind: ReactionKind) => void;
  /** The picker opened or closed, or a reaction was given (rotating feeds wait). */
  onActivity?: (pickerOpen: boolean) => void;
}

/**
 * Heart, pray along and an emoji of the listener's choice. The song's picker
 * opens *below* the buttons, unlike the preview's: above them it would sit
 * in front of the YouTube player, which YouTube does not allow.
 */
export function Reactions({ markId, variant, onSend, onActivity }: Props) {
  const { t } = useTranslation();
  const mark = useReactions((s) => s.marks[markId] ?? NO_MARK);
  const toggle = useReactions((s) => s.toggle);
  const pick = useReactions((s) => s.pick);
  const [open, setOpen] = useState(false);
  const pickerId = useId();
  const group = useRef<HTMLDivElement>(null);
  const picker = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLButtonElement>(null);
  const song = variant === 'song';
  const emojis = song ? SONG_EMOJI : FEED_EMOJI;
  const chosen = emojis.find((e) => e.kind === mark.emoji) ?? null;

  // The parent's callback, current without re-running the effects below:
  // re-running the focus effect would pull focus back to the first emoji
  // every time the parent renders (the progress bar does so twice a second).
  const activity = useRef(onActivity);
  useEffect(() => {
    activity.current = onActivity;
  }, [onActivity]);

  const close = (returnFocus: boolean) => {
    setOpen(false);
    activity.current?.(false);
    if (returnFocus) opener.current?.focus();
  };

  useEffect(() => {
    if (open) picker.current?.querySelector('button')?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const outside = (e: Event) => {
      const target = e.target as Node;
      if (!group.current?.contains(target) && !picker.current?.contains(target)) {
        setOpen(false);
        activity.current?.(false);
      }
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      e.preventDefault();
      setOpen(false);
      activity.current?.(false);
      opener.current?.focus();
    };
    document.addEventListener('click', outside);
    document.addEventListener('focusin', outside);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('click', outside);
      document.removeEventListener('focusin', outside);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);

  const press = (kind: 'heart' | 'pray') => {
    if (toggle(markId, kind)) onSend(kind);
    activity.current?.(false);
  };
  const choose = (e: Emoji) => {
    if (pick(markId, e.kind)) onSend(e.kind);
    close(true);
  };

  const buttonClass = song ? 'icon-button' : 'feed-reaction';
  const opened = (
    <button
      ref={opener}
      type="button"
      className={song ? 'icon-button' : 'feed-reaction feed-emoji-toggle'}
      aria-expanded={open}
      aria-controls={open ? pickerId : undefined}
      aria-pressed={song ? undefined : !!chosen}
      aria-label={chosen ? t('reactions.chosen', { name: t(chosen.label) }) : t('reactions.chooseEmoji')}
      onClick={() => {
        const next = !open;
        setOpen(next);
        activity.current?.(next);
      }}
    >
      {chosen ? (
        <span className="selected-emoji" aria-hidden="true">
          {chosen.emoji}
        </span>
      ) : (
        <SmileIcon />
      )}
    </button>
  );

  const options = emojis.map((e) => (
    <button key={e.kind} type="button" aria-label={t(e.label)} aria-pressed={mark.emoji === e.kind} onClick={() => choose(e)}>
      {e.emoji}
    </button>
  ));

  return (
    <>
      <div ref={group} className={song ? 'reactions' : 'feed-reactions'} role="group" aria-label={t(song ? 'reactions.song' : 'reactions.message')}>
        <button type="button" className={buttonClass} data-reaction="heart" aria-pressed={mark.heart} aria-label={t('reactions.heart')} onClick={() => press('heart')}>
          <HeartIcon />
        </button>
        <button
          type="button"
          className={buttonClass}
          data-reaction="prayer"
          aria-pressed={mark.pray}
          aria-label={t(song ? 'reactions.pray' : 'reactions.prayed')}
          onClick={() => press('pray')}
        >
          {variant === 'prayer' ? <span aria-hidden="true">🙏</span> : <PrayIcon />}
        </button>
        {opened}
        {song && open && (
          <div ref={picker} id={pickerId} className="emoji-picker" role="group" aria-label={t('reactions.emojis')}>
            <p>{t('reactions.yours')}</p>
            <div className="emoji-options">{options}</div>
          </div>
        )}
      </div>
      {!song && open && (
        <div ref={picker} id={pickerId} className="feed-emoji-picker" role="group" aria-label={t('reactions.emojis')}>
          {options}
        </div>
      )}
    </>
  );
}
