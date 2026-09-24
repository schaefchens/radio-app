import type { Lang } from '@arche/shared';

/**
 * The station page (/about): what ARCHE is, the imprint and the privacy
 * policy. The operator details come from schaefchens.de (same operator).
 *
 * The privacy policy describes what this code actually does — keep them in
 * step: retention periods live in server/app/Tick/Tick.php (purge) and
 * server/config/defaults.php, the YouTube consent in components/stage, the
 * AI services in server/app/Ai. The German text is the binding one.
 */

export interface Block {
  h: string;
  /** Paragraphs; a single `\n` is a line break. URLs and e-mail addresses become links. */
  p: string;
}

export interface StationTexts {
  about: Block[];
  imprint: Block[];
  privacy: Block[];
  /** Shown above the English legal texts. */
  bindingNote: string | null;
}

const CONTACT = 'app.support@schaefchens.de';
const OPERATOR = 'Christoph Scharf\nMühltorstraße 1\n67245 Lambsheim';

const de = (host: string): StationTexts => ({
  bindingNote: null,
  about: [
    {
      h: 'Ein Programm. Viele Nationen. Eine Familie.',
      p:
        'ARCHE ist ein christliches Community-Radio. Alle, die einschalten, hören im selben Moment denselben Song, ' +
        'dasselbe Gebet und dieselbe Geschichte – egal, wo auf der Welt sie gerade sind.',
    },
    {
      h: 'Musik, Moderation, Gemeinschaft',
      p:
        'Die Musik kommt von YouTube: Lobpreis-Songs, ausgewählt von unserem Team und gewünscht von Hörerinnen und ' +
        `Hörern. Zwischen den Songs spricht ${host}, unser Host, auf Deutsch und Englisch – über das, was gerade läuft, ` +
        'über eure Wünsche und Gebetsanliegen.',
    },
    {
      h: `${host} ist eine KI`,
      p:
        `Die Moderationstexte von ${host} schreibt ein KI-Modell, gesprochen werden sie von einer synthetischen Stimme ` +
        '(beides von OpenAI). Welche Songs und Sendungen laufen, entscheidet unser Team. Einsendungen werden ' +
        'automatisch geprüft, bevor sie auf Sendung gehen.',
    },
    {
      h: 'Mitmachen',
      p:
        'Wünsch dir einen Song, erzähl deine Geschichte, ein Zeugnis oder einen Gruß als Sprachaufnahme, oder schick ' +
        `uns ein Gebetsanliegen – ${host} betet auf Sendung dafür. In den Community-Räumen kannst du mit anderen ` +
        'Hörerinnen und Hörern schreiben. ARCHE braucht kein Konto; wer möchte, nimmt seine Identität mit einer ' +
        'Passphrase aus 12 Wörtern auf ein anderes Gerät mit.',
    },
    {
      h: 'Ein Projekt von Schäfchens',
      p: `ARCHE ist ein nicht-kommerzielles Projekt von Schäfchens (https://schaefchens.de). Fragen und Hinweise: ${CONTACT}`,
    },
  ],
  imprint: [
    { h: 'Angaben gemäß § 5 DDG', p: `${OPERATOR}\nDeutschland` },
    { h: 'Kontakt', p: `E-Mail: ${CONTACT}` },
    { h: 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV', p: 'Christoph Scharf, Anschrift wie oben' },
    { h: 'Umsatzsteuer', p: 'ARCHE wird nicht gewerblich angeboten. Es wird keine Umsatzsteuer ausgewiesen.' },
    {
      h: 'Online-Streitbeilegung und Verbraucherschlichtung',
      p: 'Zur Teilnahme an einem Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle sind wir nicht verpflichtet und nicht bereit.',
    },
    {
      h: 'KI-generierte Inhalte',
      p: 'Die Moderationstexte und die Stimme des Hosts werden mit künstlicher Intelligenz erzeugt.',
    },
    {
      h: 'Haftung für Inhalte und Links',
      p:
        'Die Inhalte dieser App werden mit Sorgfalt erstellt. Für Richtigkeit, Vollständigkeit und Aktualität kann ' +
        'keine Gewähr übernommen werden. Beiträge von Hörerinnen und Hörern geben deren eigene Sicht wieder. Die ' +
        'Musikvideos werden über den YouTube-Player eingebunden; für ihre Inhalte sind die jeweiligen Rechteinhaber ' +
        'verantwortlich. Für Inhalte verlinkter externer Seiten sind deren Betreiber verantwortlich; zum Zeitpunkt der ' +
        'Verlinkung waren keine Rechtsverstöße erkennbar.',
    },
    {
      h: 'Urheberrecht',
      p: 'Texte und Gestaltung von ARCHE sind urheberrechtlich geschützt. Die Rechte an der Musik liegen bei den jeweiligen Urheberinnen und Urhebern.',
    },
  ],
  privacy: [
    { h: 'Verantwortlicher', p: `${OPERATOR}, Deutschland\nE-Mail: ${CONTACT}` },
    {
      h: 'Das Wichtigste in Kürze',
      p:
        'ARCHE funktioniert ohne Konto, ohne Werbung, ohne Analyse- oder Tracking-Dienste und ohne Cookies des ' +
        'Betreibers. Wir verarbeiten nur, was für das Radio, Ihre Einsendungen und die Community-Räume nötig ist. ' +
        'Schriftarten und Vorschaubilder liefern wir von unserem eigenen Server aus.',
    },
    {
      h: 'Hosting und Server-Logfiles',
      p:
        'App, Programm und Schnittstellen liegen auf einem Webhosting der Hetzner Online GmbH, Industriestr. 25, ' +
        '91710 Gunzenhausen, Deutschland, die in unserem Auftrag tätig ist (Art. 28 DSGVO). Beim Aufruf verarbeitet ' +
        'der Hoster technisch notwendige Daten (IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, Browser und ' +
        'Betriebssystem) und löscht sie kurzfristig. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO: der sichere ' +
        'Betrieb des Angebots.',
    },
    {
      h: 'Gerätekennung und Speicherung auf Ihrem Gerät',
      p:
        'Beim ersten Öffnen legt die App im Speicher Ihres Browsers eine zufällige Gerätekennung samt Geheimnis an ' +
        '(kein Cookie). Damit erkennt der Server Ihr Gerät wieder: für den Status Ihrer Einsendungen, Ihre Reaktionen, ' +
        'den Schutz vor Missbrauch und die Zählung der Zuhörenden. Auf dem Server liegen davon nur Prüfwerte, die mit ' +
        'einem geheimen Schlüssel gebildet sind (HMAC). Lokal speichert die App außerdem Ihre Einstellungen (Sprache, ' +
        'Lautstärke, Kanal, Ihre YouTube-Einwilligung) und – nur wenn Sie eine anlegen – Ihre Passphrase.\n' +
        'Das Speichern ist für den von Ihnen genutzten Dienst unbedingt erforderlich (§ 25 Abs. 2 Nr. 2 TDDDG); ' +
        'Rechtsgrundlage der Verarbeitung ist Art. 6 Abs. 1 lit. f DSGVO. Während Sie zuhören, meldet die App alle ' +
        'zwei Minuten, dass Ihr Gerät dabei ist; diese Einträge löschen wir nach einem Tag, Einträge des ' +
        'Missbrauchsschutzes (mit gehashter IP-Adresse) nach zwei Tagen, Gerätekennungen ohne Einsendungen und ohne ' +
        'Passphrase nach 60 Tagen ohne Nutzung.',
    },
    {
      h: 'YouTube',
      p:
        'Die Musikvideos sind im erweiterten Datenschutzmodus (youtube-nocookie.com) eingebunden, einem Dienst der ' +
        'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland. Der Player wird erst geladen, wenn Sie ' +
        '„Tap to join live“ antippen – vorher baut die App keine Verbindung zu Google auf. Mit dem Antippen willigen ' +
        'Sie ein, dass Google Ihre IP-Adresse, Angaben zu Ihrem Gerät und die abgespielten Videos verarbeitet und ' +
        'Informationen auf Ihrem Gerät speichern kann (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG). Dasselbe gilt ' +
        'für die Vorschau eines gewünschten Songs. Google kann Daten in die USA übermitteln; Google LLC ist unter dem ' +
        'EU-US Data Privacy Framework zertifiziert. Sie können die Einwilligung jederzeit unten unter ' +
        '„Datenschutz-Einstellungen“ widerrufen. Mehr: https://policies.google.com/privacy',
    },
    {
      h: 'Einsendungen: Songwünsche, Aufnahmen, Gebetsanliegen',
      p:
        'Wenn Sie einen Song wünschen, eine Sprachaufnahme einsenden oder ein Gebetsanliegen schicken, verarbeiten wir ' +
        'Ihre Angaben (Vorname, Ort, Ihren Text bzw. Ihre Aufnahme, bei Songwünschen den Link und eine Widmung) ' +
        'zusammen mit Ihrer Gerätekennung, um den Beitrag zu prüfen und zu senden. Rechtsgrundlage ist Ihre ' +
        'Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Gebetsanliegen, Zeugnisse und Geschichten können Ihre religiöse ' +
        'Überzeugung oder Angaben zur Gesundheit erkennen lassen; mit dem Absenden willigen Sie ausdrücklich ein, dass ' +
        'wir diese Angaben dafür verarbeiten (Art. 9 Abs. 2 lit. a DSGVO).\n' +
        'Auf Sendung nennen wir nur Vorname und Ort. Ein Gebetsanliegen erscheint zusätzlich als „Stimme der ' +
        'Community“ auf der Startseite, wenn Sie das auswählen. Aufnahmen werden erst nach der Freigabe ' +
        'veröffentlicht und nur wiederholt, wenn Sie dem zugestimmt haben. Ein gewünschter Song kann in unsere ' +
        'Musikauswahl aufgenommen werden – ohne Ihren Namen und ohne Ihre Widmung.\n' +
        'Abgelehnte Aufnahmen löschen wir sofort, alle übrigen Einsendungen nach 90 Tagen. Aufnahmen, die Sie zur ' +
        'Wiederholung freigegeben haben, bleiben, bis Sie widersprechen. Ihre Einwilligungen können Sie jederzeit ' +
        `mit Wirkung für die Zukunft per E-Mail an ${CONTACT} widerrufen.`,
    },
    {
      h: 'KI-Dienste (OpenAI)',
      p:
        'Für die Prüfung der Einsendungen, die Texte und die Stimme des Hosts und die Verschriftlichung von Aufnahmen ' +
        'nutzen wir die Programmierschnittstelle der OpenAI Ireland Limited, 1st Floor, The Liffey Trust Centre, ' +
        '117–126 Sheriff Street Upper, Dublin 1, D01 YC43, Irland, die in unserem Auftrag tätig ist. Dabei ' +
        'übermitteln wir Ihren eingesandten Text bzw. Ihre Aufnahme und deren Abschrift, Vorname und Ort, bei ' +
        'Songwünschen die Widmung und – für die Auswahl der Community-Stimmen – die betreffenden Chat-Nachrichten. ' +
        'Nach Angaben von OpenAI werden über die Schnittstelle übermittelte Daten nicht zum Training verwendet und bis ' +
        'zu 30 Tage zur Missbrauchserkennung gespeichert. Dabei können Daten in die USA übermittelt werden; ' +
        'Grundlage sind die Standardvertragsklauseln der EU-Kommission. Rechtsgrundlage sind Ihre Einwilligung zur ' +
        'Einsendung (Art. 6 Abs. 1 lit. a, Art. 9 Abs. 2 lit. a DSGVO) und unser berechtigtes Interesse an einem ' +
        'sicheren, moderierten Programm (Art. 6 Abs. 1 lit. f DSGVO). Mehr: https://openai.com/policies/privacy-policy',
    },
    {
      h: 'Community-Räume',
      p:
        'Die Räume laufen auf Servern der Hetzner Online GmbH in Deutschland, die wir bei Bedarf starten und bei ' +
        'Inaktivität wieder löschen. Für die Teilnahme verarbeiten wir Ihren Anzeigenamen, Ihr Land (falls angegeben), ' +
        'Ihre Nachrichten und Reaktionen. Nachrichten liegen nur im Arbeitsspeicher des Raumservers (die letzten 150 ' +
        'je Raum) und verschwinden mit ihm. Viel geliebte Nachrichten können nach einer automatischen Prüfung bis zu ' +
        'zwei Stunden als „Stimme der Community“ auf der Startseite erscheinen; wir löschen sie nach sieben Tagen. ' +
        'Gemeldete Nachrichten sehen unsere Moderatorinnen und Moderatoren; Meldungen löschen wir nach 30 Tagen. ' +
        'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (die Räume, die Sie nutzen) und lit. f (Schutz vor Missbrauch).',
    },
    {
      h: 'Passphrase',
      p:
        'Legen Sie freiwillig eine Passphrase an, erzeugt Ihr Gerät daraus Zugangsdaten; die 12 Wörter verlassen Ihr ' +
        'Gerät nie. Auf dem Server liegt nur ein Prüfwert (Argon2id). So nehmen Sie Ihre Identität auf ein anderes ' +
        'Gerät mit. Eine Wiederherstellung ist nicht möglich. Identitäten mit Passphrase löschen wir auf Wunsch ' +
        '(Art. 6 Abs. 1 lit. b DSGVO).',
    },
    {
      h: 'Datensicherung',
      p: 'Von der Datenbank legen wir täglich eine Sicherung an und bewahren die letzten sieben auf. Gelöschte Daten verschwinden daher spätestens nach einer Woche auch aus den Sicherungen.',
    },
    {
      h: 'Ihre Rechte',
      p:
        'Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, ' +
        'Datenübertragbarkeit und Widerspruch sowie das Recht, erteilte Einwilligungen zu widerrufen. Schreiben Sie ' +
        `uns dazu an ${CONTACT}. Beschwerden können Sie an die zuständige Aufsichtsbehörde richten: den ` +
        'Landesbeauftragten für den Datenschutz und die Informationsfreiheit Rheinland-Pfalz.',
    },
  ],
});

const en = (host: string): StationTexts => ({
  bindingNote: 'The imprint and privacy policy in English are provided for convenience; the German version is legally binding.',
  about: [
    {
      h: 'One program. Many nations. One family.',
      p:
        'ARCHE is a Christian community radio. Everyone who tunes in hears the same song, the same prayer and the ' +
        'same story at the same moment — wherever in the world they are.',
    },
    {
      h: 'Music, a host, a community',
      p:
        'The music comes from YouTube: worship songs chosen by our team and requested by listeners. Between the songs ' +
        `${host}, our host, speaks in English and German — about what is playing, your requests and your prayer requests.`,
    },
    {
      h: `${host} is an AI`,
      p:
        `${host}'s words are written by an AI model and spoken by a synthetic voice (both from OpenAI). Our team ` +
        'decides which songs and programs run. Submissions are checked automatically before they go on air.',
    },
    {
      h: 'Take part',
      p:
        'Request a song, share your story, a testimony or a greeting as a voice recording, or send a prayer request — ' +
        `${host} prays for it on air. In the community rooms you can write with other listeners. ARCHE needs no ` +
        'account; if you like, a passphrase of 12 words takes your identity to another device.',
    },
    {
      h: 'A project of Schäfchens',
      p: `ARCHE is a non-commercial project of Schäfchens (https://schaefchens.de). Questions and feedback: ${CONTACT}`,
    },
  ],
  imprint: [
    { h: 'Details pursuant to § 5 DDG', p: `${OPERATOR}\nGermany` },
    { h: 'Contact', p: `Email: ${CONTACT}` },
    { h: 'Responsible for content under § 18 (2) MStV', p: 'Christoph Scharf, address as above' },
    { h: 'VAT', p: 'ARCHE is not offered commercially. No VAT is charged.' },
    {
      h: 'Online dispute resolution',
      p: 'We are neither obliged nor willing to take part in dispute resolution proceedings before a consumer arbitration board.',
    },
    { h: 'AI-generated content', p: "The host's words and voice are generated with artificial intelligence." },
    {
      h: 'Liability for content and links',
      p:
        'The content of this app is compiled with care. No guarantee can be given for accuracy, completeness or ' +
        "timeliness. Listeners' contributions express their own views. The music videos are embedded through the " +
        'YouTube player; their rights holders are responsible for their content. The operators of linked external ' +
        'sites are responsible for their content; no legal violations were apparent at the time of linking.',
    },
    { h: 'Copyright', p: "ARCHE's texts and design are protected by copyright. The rights to the music belong to its creators." },
  ],
  privacy: [
    { h: 'Controller', p: `${OPERATOR}, Germany\nEmail: ${CONTACT}` },
    {
      h: 'In short',
      p:
        'ARCHE works without an account, without advertising, without analytics or tracking and without cookies of ' +
        'its own. We process only what the radio, your submissions and the community rooms need. Fonts and thumbnails ' +
        'come from our own server.',
    },
    {
      h: 'Hosting and server logs',
      p:
        'The app, the program and the interfaces run on web hosting by Hetzner Online GmbH, Industriestr. 25, 91710 ' +
        'Gunzenhausen, Germany, on our behalf (Art. 28 GDPR). When you visit, the host processes technically ' +
        'necessary data (IP address, date and time, address requested, browser and operating system) and deletes it ' +
        'shortly afterwards. Legal basis: Art. 6(1)(f) GDPR, the secure operation of the service.',
    },
    {
      h: 'Device id and storage on your device',
      p:
        "On first start the app stores a random device id with a secret in your browser's storage (not a cookie). It " +
        'lets the server recognise your device: for the status of your submissions, your reactions, protection ' +
        'against abuse and counting listeners. The server keeps only values derived with a secret key (HMAC). The app ' +
        'also stores your settings locally (language, volume, channel, your YouTube consent) and — only if you create ' +
        'one — your passphrase.\n' +
        'This storage is strictly necessary for the service you use (§ 25(2) no. 2 TDDDG); the legal basis is ' +
        'Art. 6(1)(f) GDPR. While you listen, the app reports every two minutes that your device is there; we delete ' +
        'these entries after one day, abuse-protection entries (with a hashed IP address) after two days, and device ' +
        'ids without submissions or a passphrase after 60 days without use.',
    },
    {
      h: 'YouTube',
      p:
        'The music videos are embedded in privacy-enhanced mode (youtube-nocookie.com), a service of Google Ireland ' +
        'Limited, Gordon House, Barrow Street, Dublin 4, Ireland. The player loads only when you tap “Tap to join ' +
        'live” — before that, the app makes no connection to Google. By tapping you consent that Google processes ' +
        'your IP address, information about your device and the videos played, and may store information on your ' +
        'device (Art. 6(1)(a) GDPR, § 25(1) TDDDG). The same applies to the preview of a requested song. Google may ' +
        'transfer data to the USA; Google LLC is certified under the EU-US Data Privacy Framework. You can withdraw ' +
        'your consent at any time under “Privacy settings” below. More: https://policies.google.com/privacy',
    },
    {
      h: 'Submissions: song requests, recordings, prayer requests',
      p:
        'When you request a song, send a voice recording or a prayer request, we process what you give us (first ' +
        'name, place, your text or recording, for a song request the link and a dedication) together with your device ' +
        'id, to check and air it. The legal basis is your consent (Art. 6(1)(a) GDPR). Prayer requests, testimonies ' +
        'and stories can reveal religious beliefs or health information; by sending, you explicitly consent to our ' +
        'processing them for this purpose (Art. 9(2)(a) GDPR).\n' +
        'On air we mention only your first name and place. A prayer request also appears as a “community voice” on ' +
        'the home screen if you choose so. Recordings are published only after approval and replayed only with your ' +
        'consent. A requested song may join our music selection — without your name and without your dedication.\n' +
        'We delete rejected recordings immediately and all other submissions after 90 days. Recordings you allowed ' +
        'to be replayed stay until you object. You can withdraw your consent at any time, for the future, by email to ' +
        `${CONTACT}.`,
    },
    {
      h: 'AI services (OpenAI)',
      p:
        "To check submissions, for the host's words and voice and to transcribe recordings we use the API of OpenAI " +
        'Ireland Limited, 1st Floor, The Liffey Trust Centre, 117–126 Sheriff Street Upper, Dublin 1, D01 YC43, ' +
        'Ireland, on our behalf. We send your text or recording and its transcript, first name and place, for song ' +
        'requests the dedication and — to select community voices — the chat messages concerned. According to ' +
        'OpenAI, data sent through the API is not used for training and is stored for up to 30 days to detect abuse. ' +
        "Data may be transferred to the USA on the basis of the EU Commission's standard contractual clauses. Legal " +
        'basis: your consent to the submission (Art. 6(1)(a), Art. 9(2)(a) GDPR) and our legitimate interest in a ' +
        'safe, moderated program (Art. 6(1)(f) GDPR). More: https://openai.com/policies/privacy-policy',
    },
    {
      h: 'Community rooms',
      p:
        'The rooms run on servers of Hetzner Online GmbH in Germany, started when needed and deleted when idle. To take ' +
        'part we process your display name, your country (if given), your messages and reactions. Messages exist only ' +
        "in the room server's memory (the last 150 per room) and disappear with it. Much-loved messages may appear, " +
        'after an automated check, as a “community voice” on the home screen for up to two hours; we delete them after ' +
        'seven days. Our moderators see reported messages; we delete reports after 30 days. Legal basis: Art. 6(1)(b) ' +
        'GDPR (the rooms you use) and (f) (protection against abuse).',
    },
    {
      h: 'Passphrase',
      p:
        'If you choose to create a passphrase, your device derives credentials from it; the 12 words never leave your ' +
        'device. The server keeps only a check value (Argon2id). This lets you take your identity to another device. ' +
        'It cannot be recovered. We delete identities with a passphrase on request (Art. 6(1)(b) GDPR).',
    },
    {
      h: 'Backups',
      p: 'We back up the database daily and keep the last seven copies, so deleted data disappears from the backups within a week.',
    },
    {
      h: 'Your rights',
      p:
        'You have the right of access, rectification, erasure, restriction of processing, data portability and ' +
        `objection, and the right to withdraw consent. Write to us at ${CONTACT}. You can lodge a complaint with the ` +
        'competent supervisory authority: the State Commissioner for Data Protection and Freedom of Information of ' +
        'Rhineland-Palatinate.',
    },
  ],
});

export function stationTexts(lang: Lang, hostName: string): StationTexts {
  return lang === 'de' ? de(hostName) : en(hostName);
}
