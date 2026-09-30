import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import type { Voice } from '@arche/shared';
import { reactVoice } from '@/lib/radio';
import { ago } from '@/lib/format';
import { useServerNow } from './useServerNow';
import { Reactions } from './Reactions';
import { useVoices } from './useVoices';
import { ChatIcon } from '@/components/common/icons';

/** Desktop shows this many; the phone carousel rotates through all of them. */
const DESKTOP_VOICES = 4;

/** "Community voices" on desktop, beside the player. */
export function CommunityVoices() {
  const { t } = useTranslation();
  const voices = useVoices();
  return (
    <section className="card community" aria-label={t('voices.title')}>
      <VoicesHeading />
      {voices.length === 0 && <p className="feed-empty">{t('voices.empty')}</p>}
      {voices.slice(0, DESKTOP_VOICES).map((v) => (
        <VoiceMessage key={v.id} voice={v} />
      ))}
    </section>
  );
}

export function VoicesHeading() {
  const { t } = useTranslation();
  return (
    <div className="section-heading">
      <ChatIcon />
      <h2>{t('voices.title')}</h2>
      <Link to="/chat" className="text-button">
        {t('voices.seeMore')} →
      </Link>
    </div>
  );
}

export function VoiceMessage({ voice, onActivity }: { voice: Voice; onActivity?: (pickerOpen: boolean) => void }) {
  const { i18n } = useTranslation();
  const now = useServerNow(30_000);
  return (
    <div className="quote feed-message">
      <div className="message-copy">
        <strong>{voice.name}</strong>
        {voice.at > 0 && <time dateTime={new Date(voice.at).toISOString()}>{ago(voice.at, now, i18n.language)}</time>}
        <p>{voice.text}</p>
      </div>
      <Reactions markId={`voice:${voice.id}`} variant="feed" onSend={(kind) => reactVoice(voice.id, kind)} onActivity={onActivity} />
    </div>
  );
}
