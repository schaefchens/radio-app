import type { Lang } from '@arche/shared';
import type { Block } from './legal';

/**
 * The terms of use and community rules (/regeln): what a listener agrees to
 * before their first post — a chat message, a request, a prayer, a recording
 * (components/common/RulesConsent.tsx). The app stores require them for user
 * content: no tolerance for objectionable content or abusive users, reports
 * answered within a day. The German text is the binding one.
 *
 * Changing them in substance: raise RULES_VERSION, and everyone agrees again
 * before their next post.
 */
export const RULES_VERSION = 1;

const CONTACT = 'app.support@schaefchens.de';
const DELETE_URL = 'https://radio.schaefchens.de/konto-loeschen';

const de: Block[] = [
  {
    h: 'Wofür diese Regeln gelten',
    p:
      'Für alles, was Sie bei Arche Radio beitragen – in der App und auf radio.schaefchens.de: Nachrichten und ' +
      'Anzeigenamen in den Community-Räumen, Songwünsche, Videovorschläge (Predigten, Glaubenszeugnisse, Missionsvideos, ' +
      'Filme), Sprachaufnahmen und Gebetsanliegen. Vor ' +
      'Ihrem ersten Beitrag stimmen Sie ihnen zu; zum Zuhören brauchen Sie nichts davon.',
  },
  {
    h: 'Ab 16 Jahren',
    p: 'Beiträge sind ab 16 Jahren möglich, Jüngere brauchen das Einverständnis ihrer Eltern.',
  },
  {
    h: 'Null Toleranz',
    p:
      'Arche Radio duldet keine anstößigen Inhalte und keine missbräuchlichen Nutzerinnen und Nutzer. Nicht erlaubt ' +
      'sind: Beleidigung, Belästigung, Mobbing und Drohungen; Hass und Hetze gegen Menschen wegen ihrer Herkunft, ' +
      'Hautfarbe, Religion, ihres Geschlechts, ihrer sexuellen Orientierung, einer Behinderung oder ihres Alters; ' +
      'sexuelle Inhalte; Gewalt, ihre Verherrlichung und Aufrufe zu Gewalt oder Selbstverletzung; extremistische ' +
      'und strafbare Inhalte; Spott über den Glauben anderer; Werbung, Spam, fremde Links und Bitten um Geld oder ' +
      'Spenden; Betrug und das Vortäuschen einer anderen Person (auch unseres Teams oder des Hosts); Namen, Adressen ' +
      'oder Telefonnummern anderer ohne deren Einverständnis; Inhalte, an denen Sie keine Rechte haben. Auch ' +
      'Anzeigenamen dürfen nichts davon enthalten.',
  },
  {
    h: 'Wie wir prüfen',
    p:
      'Einsendungen prüfen wir automatisch, bevor sie gesendet werden oder an der Gebetswand erscheinen. Nachrichten ' +
      'und Namen in den Räumen prüft ein Wortfilter; Community-Stimmen erscheinen erst nach einer Prüfung. Einen ' +
      'Anspruch auf Veröffentlichung gibt es nicht.',
  },
  {
    h: 'Melden und Blockieren',
    p:
      'Mit „Melden“ bei einer Nachricht, einer Community-Stimme oder einem Gebetsanliegen sagen Sie uns Bescheid; der ' +
      'Beitrag wird dann für Sie ausgeblendet. „Blockieren“ blendet alle Nachrichten einer Person auf Ihrem Gerät ' +
      'aus, und wir erfahren davon; im Profil heben Sie eine Blockierung wieder auf. Meldungen nehmen wir auch unter ' +
      `${CONTACT} entgegen.`,
  },
  {
    h: 'Was wir tun',
    p:
      'Jede Meldung prüfen wir innerhalb von 24 Stunden. Verstöße entfernen wir und schließen ihre Verfasserinnen und ' +
      'Verfasser von den Räumen und von Einsendungen aus – bei schweren Verstößen ohne Vorwarnung. Ein ' +
      'Gebetsanliegen, das mehrere melden, nehmen wir sofort ab, bis wir es geprüft haben. Strafbare Inhalte können ' +
      'wir den Behörden melden.',
  },
  {
    h: 'YouTube',
    p:
      'Musik, Predigten, Glaubenszeugnisse, Missionsvideos und Filme spielt Arche Radio über die YouTube-API-Dienste ab. ' +
      'Mit der Nutzung stimmen Sie auch den ' +
      'Nutzungsbedingungen von YouTube zu: https://www.youtube.com/t/terms',
  },
  {
    h: 'Ihr Konto',
    p: `Sie können Ihr Konto jederzeit löschen – im Profil unter „Konto löschen“ oder auf ${DELETE_URL}`,
  },
  {
    h: 'Änderungen und Kontakt',
    p:
      'Ändern wir diese Regeln wesentlich, stimmen Sie vor Ihrem nächsten Beitrag erneut zu. Anbieter: siehe ' +
      `Impressum. Kontakt: ${CONTACT}`,
  },
];

const en: Block[] = [
  {
    h: 'What these rules cover',
    p:
      'Everything you contribute to Arche Radio — in the app and on radio.schaefchens.de: messages and display names ' +
      'in the community rooms, song requests, video suggestions (preachings, testimonies, mission videos, films), voice ' +
      'recordings and prayer requests. You agree ' +
      'to them before your first contribution; listening needs none of this.',
  },
  {
    h: 'From 16',
    p: 'You can contribute from the age of 16; younger listeners need their parents’ consent.',
  },
  {
    h: 'Zero tolerance',
    p:
      'Arche Radio tolerates no objectionable content and no abusive users. Not allowed: insults, harassment, bullying ' +
      'and threats; hate against people because of their origin, colour, religion, gender, sexual orientation, ' +
      'disability or age; sexual content; violence, glorifying it, and calls for violence or self-harm; extremist and ' +
      'illegal content; mocking the faith of others; advertising, spam, links to other sites, and asking for money or ' +
      'donations; fraud and pretending to be someone else (including our team or the host); names, addresses or phone ' +
      'numbers of others without their consent; content you hold no rights to. Display names may contain none of this ' +
      'either.',
  },
  {
    h: 'How we check',
    p:
      'Submissions are checked automatically before they go on air or appear on the prayer wall. A word filter checks ' +
      'messages and names in the rooms; community voices appear only after a check. There is no right to publication.',
  },
  {
    h: 'Report and block',
    p:
      '“Report” on a message, a community voice or a prayer request tells us; it is then hidden for you. “Block” hides ' +
      'all of a person’s messages on your device, and we are told; you can unblock them in your profile. Reports are ' +
      `also welcome at ${CONTACT}.`,
  },
  {
    h: 'What we do',
    p:
      'We check every report within 24 hours. We remove violations and exclude their authors from the rooms and from ' +
      'submissions — for serious violations without warning. A prayer request reported by several listeners comes off ' +
      'the wall at once until we have checked it. We may report illegal content to the authorities.',
  },
  {
    h: 'YouTube',
    p:
      'Arche Radio plays music, preachings, testimonies, mission videos and films through the YouTube API Services. ' +
      'By using it you also agree to the ' +
      'YouTube Terms of Service: https://www.youtube.com/t/terms',
  },
  {
    h: 'Your account',
    p: `You can delete your account at any time — in your profile under “Delete account” or at ${DELETE_URL}`,
  },
  {
    h: 'Changes and contact',
    p:
      'If we change these rules in substance, you agree again before your next contribution. Provider: see the ' +
      `imprint. Contact: ${CONTACT}`,
  },
];

export function rulesText(lang: Lang): Block[] {
  return lang === 'de' ? de : en;
}
