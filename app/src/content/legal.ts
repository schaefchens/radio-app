import type { Lang } from '@arche/shared';

/**
 * The station page (/about): what Arche Radio is, the imprint and the privacy
 * policy. The operator details come from schaefchens.de (same operator).
 *
 * The privacy policy describes what this code actually does — keep them in
 * step: retention periods live in server/app/Tick/Tick.php (purge) and
 * server/config/defaults.php, the YouTube consent in components/stage, the
 * AI services in server/app/Ai, the CDN in server/app/Cdn and
 * scripts/cdn/setup-bunny.sh (its log settings). The German text is the
 * binding one.
 */

export interface Block {
  h: string;
  /** Paragraphs; a single `\n` is a line break. URLs and e-mail addresses become links. */
  p: string;
}

export interface StationTexts {
  /** Shown first on the station page, above everything else. */
  notice: string;
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
  notice: 'Arche Radio ist ein eigenständiges Projekt und hat trotz seines Namens nichts mit der Arche Gemeinde in Hamburg zu tun.',
  about: [
    {
      h: 'Ein Programm. Viele Nationen. Eine Familie.',
      p:
        'Arche Radio ist ein christliches Community-Radio. Alle, die einschalten, hören im selben Moment denselben Song, ' +
        'dasselbe Gebet und dieselbe Geschichte – egal, wo auf der Welt sie gerade sind.',
    },
    {
      h: 'Musik, Moderation, Gemeinschaft',
      p:
        'Die Musik kommt von YouTube: Lobpreis-Songs, ausgewählt von unserem Team und gewünscht von ' +
        `Hörern; in Predigt-, Zeugnis-, Missions- und Filmsendungen laufen dazu Predigten, Glaubenszeugnisse, Videos aus der Mission und christliche Filme. Zwischen den Songs spricht ${host}, unser Host, auf Deutsch ` +
        'und Englisch – über das, was gerade läuft, über eure Wünsche und Gebetsanliegen.',
    },
    {
      h: 'Unsere Moderation ist eine KI',
      p:
        `Jede Moderation bei Arche Radio, auch ${host}, ist eine KI: Die Moderationstexte schreibt ein KI-Modell (von OpenAI), ` +
        'gesprochen werden sie von einer synthetischen Stimme – von OpenAI oder, bei einzelnen Moderationen, von ElevenLabs ' +
        'oder von Qwen3-TTS, einem offenen Sprachmodell auf unseren eigenen Rechnern. ' +
        'Welche Songs und Sendungen laufen, entscheidet unser Team. Einsendungen werden automatisch geprüft, bevor sie auf ' +
        'Sendung gehen.',
    },
    {
      h: 'Mitmachen',
      p:
        'Wünsch dir einen Song, empfiehl ein Video – eine Predigt, ein Glaubenszeugnis, ein Missionsvideo oder einen Film –, erzähl deine Geschichte, ein Zeugnis oder einen Gruß als ' +
        `Sprachaufnahme, oder schick uns ein Gebetsanliegen – ${host} liest es auf Sendung vor und lädt alle ein, dafür zu beten. In den Community-Räumen kannst du mit anderen ` +
        'Hörern schreiben. Arche Radio braucht keine Anmeldung – keine E-Mail, kein Passwort; wer möchte, ' +
        'nimmt seine Identität mit einer Passphrase aus 12 Wörtern auf ein anderes Gerät mit. Arche Radio gibt es im ' +
        'Browser und als App für iPhone und Android.',
    },
    {
      h: 'Die Gebetsstunde',
      p:
        'In der Gebetsstunde beten Hörer füreinander: Erst sammeln wir eure Gebetsanliegen, dann liest ' +
        `${host} sie Wort für Wort vor – zuerst das tägliche Gebetsanliegen von Open Doors für verfolgte Christen ` +
        '(https://www.opendoors.de) –, und dann schickt ihr eure Gebete, gesprochen oder geschrieben, und wir senden sie. ' +
        `${host} selbst betet nie.`,
    },
    {
      h: 'Ein Projekt von Schäfchens',
      p: `Arche Radio ist ein nicht-kommerzielles Projekt von Schäfchens (https://schaefchens.de). Fragen und Hinweise: ${CONTACT}`,
    },
  ],
  imprint: [
    { h: 'Angaben gemäß § 5 DDG', p: `${OPERATOR}\nDeutschland` },
    { h: 'Kontakt', p: `E-Mail: ${CONTACT}` },
    { h: 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV', p: 'Christoph Scharf, Anschrift wie oben' },
    { h: 'Umsatzsteuer', p: 'Arche Radio wird nicht gewerblich angeboten. Es wird keine Umsatzsteuer ausgewiesen.' },
    {
      h: 'Online-Streitbeilegung und Verbraucherschlichtung',
      p: 'Zur Teilnahme an einem Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle sind wir nicht verpflichtet und nicht bereit.',
    },
    {
      h: 'KI-generierte Inhalte',
      p: 'Die Moderationstexte und die Stimmen der Moderation werden mit künstlicher Intelligenz erzeugt.',
    },
    {
      h: 'Haftung für Inhalte und Links',
      p:
        'Die Inhalte dieser App werden mit Sorgfalt erstellt. Für Richtigkeit, Vollständigkeit und Aktualität kann ' +
        'keine Gewähr übernommen werden. Beiträge von Hörern geben deren eigene Sicht wieder. Die ' +
        'Videos – Musik, Predigten, Glaubenszeugnisse, Missionsvideos und Filme – werden über den YouTube-Player eingebunden; für ihre Inhalte sind die jeweiligen Rechteinhaber ' +
        'verantwortlich. Nach manchen Videos verlinkt die Bühne auf die Kanäle und Websites der Prediger, Gemeinden, ' +
        'Missionswerke und Musiker. Für Inhalte verlinkter externer Seiten sind deren Betreiber verantwortlich; zum Zeitpunkt der ' +
        'Verlinkung waren keine Rechtsverstöße erkennbar.',
    },
    {
      h: 'Urheberrecht',
      p: 'Texte und Gestaltung von Arche Radio sind urheberrechtlich geschützt. Die Rechte an der Musik liegen bei den jeweiligen Urhebern.',
    },
  ],
  privacy: [
    { h: 'Verantwortlicher', p: `${OPERATOR}, Deutschland\nE-Mail: ${CONTACT}` },
    {
      h: 'Das Wichtigste in Kürze',
      p:
        'Arche Radio funktioniert ohne Anmeldung (keine E-Mail, kein Passwort), ohne Werbung, ohne Analyse- oder ' +
        'Tracking-Dienste und ohne Cookies des Betreibers. Wir verarbeiten nur, was für das Radio, Ihre Einsendungen ' +
        'und die Community-Räume nötig ist. Ihr Konto mit allem, was Sie uns geschickt haben, können Sie jederzeit ' +
        'selbst löschen. ' +
        'Schriftarten liefern wir von unserem eigenen Server aus, Programm, Audiobeiträge und Bilder über das ' +
        'Content-Delivery-Netzwerk BunnyCDN.',
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
      h: 'Arche Radio als App (App Store, Google Play)',
      p:
        'Die Apps für iPhone und Android laden Sie über den App Store der Apple Distribution International Ltd. bzw. ' +
        'Google Play der Google Ireland Limited; diese verarbeiten dabei Daten in eigener Verantwortung nach ihren ' +
        'eigenen Bestimmungen. Wir erhalten von ihnen nur zusammengefasste Zahlen (etwa Downloads und Abstürze), ' +
        'keine Angaben über Sie.\n' +
        'Die App öffnet radio.schaefchens.de in einem eigenen Fenster: Für sie gilt alles, was diese Erklärung über ' +
        'die Website sagt, und „Speicher Ihres Browsers“ meint dort den Speicher der App, der mit ihr gelöscht wird. ' +
        'Die App enthält keine Analyse- oder Werbedienste. Die Android-App schließt ihre Daten von der ' +
        'Datensicherung und der Übertragung auf ein neues Gerät aus; Ihre Identität nehmen Sie mit Ihrer Passphrase mit.\n' +
        'Berechtigungen fragt Ihr Telefon erst, wenn Sie die Funktion nutzen: das Mikrofon nur für eine Aufnahme, ' +
        'die Sie starten (sie bleibt auf dem Gerät, bis Sie sie absenden), die Kamera nur für ein Foto, das ' +
        'Moderatoren für eine Sendung aufnehmen, Mitteilungen nur für Erinnerungen an Sendungen. ' +
        'Dass der Bildschirm beim Zuhören an bleibt, regelt die App auf dem Gerät; dabei werden keine Daten ' +
        'verarbeitet. Jede Berechtigung können Sie in den Einstellungen Ihres Telefons wieder entziehen.',
    },
    {
      h: 'Auslieferung über BunnyCDN',
      p:
        'Programmdateien, Audiobeiträge und Bilder liefern wir über das Content-Delivery-Netzwerk BunnyCDN der ' +
        'BunnyWay d.o.o., Dunajska cesta 165, 1000 Ljubljana, Slowenien, aus, die in unserem Auftrag tätig ist ' +
        '(Art. 28 DSGVO). Bunny betreibt Server in aller Welt; die Anfragen Ihrer App beantwortet in der Regel einer ' +
        'in Ihrer Nähe, der dafür Ihre IP-Adresse verarbeitet. In den Zugriffsprotokollen speichert Bunny keine ' +
        'IP-Adressen – so haben wir es eingestellt –, wohl aber Zeitpunkt, aufgerufene Datei, Land und Netzbetreiber, ' +
        'Browserkennung, verweisende Seite und eine technische Kennung der Verbindung; nach drei Tagen löscht Bunny ' +
        'die Protokolle. Wir zählen daraus nur, wie oft die Programmdatei einer Minute abgerufen wurde: so wissen wir, ' +
        'wie viele zuhören, ohne jemanden zu erkennen. Audiobeiträge und Bilder, die wir löschen, entfernen wir auch ' +
        'aus den Zwischenspeichern des Netzwerks. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO: eine schnelle, ' +
        'zuverlässige Auslieferung an Hörer in aller Welt. Mehr: https://bunny.net/privacy/',
    },
    {
      h: 'Gerätekennung und Speicherung auf Ihrem Gerät',
      p:
        'Beim ersten Öffnen legt die App im Speicher Ihres Browsers bzw. der App eine zufällige Gerätekennung samt Geheimnis an ' +
        '(kein Cookie). Damit erkennt der Server Ihr Gerät wieder: für den Status Ihrer Einsendungen, Ihre Reaktionen, ' +
        'den Schutz vor Missbrauch und die Zählung der Zuhörenden. Auf dem Server liegen davon nur Prüfwerte, die mit ' +
        'einem geheimen Schlüssel gebildet sind (HMAC). Lokal speichert die App außerdem Ihre Einstellungen (Sprache, ' +
        'Lautstärke, Kanal, Design, Ihre YouTube-Einwilligung, Ihre Zustimmung zu den Community-Regeln), welche ' +
        'Reaktionen Sie gegeben haben (damit sie gedrückt bleiben), wen Sie blockiert und was Sie gemeldet haben ' +
        '(damit es ausgeblendet bleibt), Ihre Sendungs-Erinnerungen, für schwache Verbindungen Programmdateien und ' +
        'Audiobeiträge der letzten Tage und – nur wenn Sie eine anlegen – Ihre Passphrase. „Daten auf diesem Gerät ' +
        'löschen“ in den Datenschutz-Einstellungen entfernt all das.\n' +
        'Das Speichern ist für den von Ihnen genutzten Dienst unbedingt erforderlich (§ 25 Abs. 2 Nr. 2 TDDDG); ' +
        'Rechtsgrundlage der Verarbeitung ist Art. 6 Abs. 1 lit. f DSGVO. Während Sie zuhören, meldet die App alle ' +
        'zwei Minuten, dass Ihr Gerät dabei ist; diese Einträge löschen wir nach einem Tag, Einträge des ' +
        'Missbrauchsschutzes (mit gehashter IP-Adresse) nach zwei Tagen, Fehlercodes des YouTube-Players (welches ' +
        'Gerät welches Video nicht abspielen konnte) nach 30 Tagen, Gerätekennungen ohne Einsendungen und ohne ' +
        'Passphrase nach 60 Tagen ohne Nutzung.',
    },
    {
      h: 'Erinnerungen an Sendungen (nur in den Apps)',
      p:
        'In den Apps können Sie sich an eine Sendung erinnern lassen. Welche Sendungen das sind, speichert die App ' +
        'nur auf Ihrem Gerät; die Erinnerungen plant das Betriebssystem Ihres Telefons als lokale Mitteilungen ein – ' +
        'etwa fünf Minuten vor Beginn, jeweils für die kommende Woche und bei jedem Öffnen der App neu. Dafür ' +
        'übermittelt die App nichts an uns und nutzt keine Push-Dienste von Apple oder Google; die Zeiten stammen aus ' +
        'dem Sendeplan, den die App ohnehin lädt. Um Erlaubnis für Mitteilungen bittet Ihr Telefon erst, wenn Sie ' +
        'zum ersten Mal „Erinnere mich“ antippen. „Daten auf diesem Gerät löschen“ entfernt auch alle geplanten ' +
        'Erinnerungen. Rechtsgrundlage ist § 25 Abs. 2 Nr. 2 TDDDG.',
    },
    {
      h: 'YouTube',
      p:
        'Die Videos – Musik, Predigten, Glaubenszeugnisse, Missionsvideos und Filme – sind im erweiterten Datenschutzmodus (youtube-nocookie.com) eingebunden, einem Dienst der ' +
        'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland. Der Player wird erst geladen, wenn Sie ' +
        '„Tap to join live“ antippen – vorher baut die App keine Verbindung zu Google auf. Mit dem Antippen willigen ' +
        'Sie ein, dass Google Ihre IP-Adresse, Angaben zu Ihrem Gerät und die abgespielten Videos verarbeitet und ' +
        'Informationen auf Ihrem Gerät speichern kann (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG). Dasselbe gilt ' +
        'für die Vorschau eines gewünschten Songs oder eines vorgeschlagenen Videos. Google kann Daten in die USA übermitteln; Google LLC ist unter dem ' +
        'EU-US Data Privacy Framework zertifiziert. Sie können die Einwilligung jederzeit unten unter ' +
        '„Datenschutz-Einstellungen“ widerrufen.\n' +
        'Arche Radio nutzt die YouTube-API-Dienste: den eingebetteten Player und, auf unserem Server, die YouTube Data ' +
        'API, an die wir nur Video-Kennungen senden, um Titel, Länge und Abspielbarkeit zu prüfen; Vorschaubilder ' +
        'liefern wir von unserem eigenen Server aus. Es gelten die Nutzungsbedingungen von YouTube ' +
        '(https://www.youtube.com/t/terms) und die Datenschutzerklärung von Google (https://policies.google.com/privacy).',
    },
    {
      h: 'Einsendungen: Songwünsche, Videovorschläge, Aufnahmen, Gebetsanliegen, Gebete',
      p:
        'Wenn Sie einen Song wünschen, ein Video vorschlagen (eine Predigt, ein Glaubenszeugnis, ein Missionsvideo oder einen Film), eine Sprachaufnahme einsenden, ein Gebetsanliegen ' +
        'schicken oder in der Gebetszeit einer Gebetsstunde ein Gebet sprechen oder schreiben, verarbeiten wir Ihre ' +
        'Angaben (Vorname, Ort, Ihren Text bzw. Ihre Aufnahme, bei Songwünschen und ' +
        'Videovorschlägen den Link und eine Widmung bzw. Ihre Begründung) ' +
        'zusammen mit Ihrer Gerätekennung, um den Beitrag zu prüfen und zu senden. Rechtsgrundlage ist Ihre ' +
        'Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Gebetsanliegen, Gebete, Zeugnisse und Geschichten können Ihre religiöse ' +
        'Überzeugung oder Angaben zur Gesundheit erkennen lassen; mit dem Absenden willigen Sie ausdrücklich ein, dass ' +
        'wir diese Angaben dafür verarbeiten (Art. 9 Abs. 2 lit. a DSGVO).\n' +
        'Von Ihnen nennen wir auf Sendung nur Vorname und Ort, die Sie angeben. Bei jeder Einsendung können Sie ' +
        '„Anonym bleiben“ ankreuzen; dann fragen wir beides nicht ab und nennen keinen Namen. Ein geschriebenes ' +
        'Gebetsanliegen lesen wir Wort für Wort vor, und die App zeigt den Text dabei an. Es erscheint nach der Freigabe ' +
        'zusätzlich an der Gebetswand der App, wenn Sie das eigens ankreuzen (das Kästchen ist nicht vorausgewählt): ' +
        'der Text, der Tag und – wenn Sie nicht anonym bleiben – Ihr Vorname und Ort. Die Wand zeigt die neuesten ' +
        'Anliegen; Ihres bleibt dort, bis neuere es verdrängen, ein Moderator es abnimmt oder wir ' +
        'es nach 90 Tagen löschen. Während es auf Sendung vorgelesen wird, zeigt die App es an der Wand als das, was ' +
        'gerade im Radio läuft. Ein abgenommenes Anliegen lesen wir nicht mehr vor.\n' +
        'In einer Gebetsstunde zeigt die Wand die Anliegen dieser Stunde: jedes ab dem Moment, in dem es vorgelesen ' +
        'wird, bis die Stunde endet – angekreuzt oder nicht, und wie überall mit Vorname und Ort, wenn Sie nicht anonym ' +
        'bleiben; das Kästchen entscheidet dort, ob Ihr Anliegen danach an der Gebetswand stehen bleibt. Ein Gebet, das ' +
        'Sie in ihrer Gebetszeit schicken, lesen wir Wort für Wort vor, mit Ihrem Vornamen und Ort, wenn Sie nicht ' +
        'anonym bleiben; ein gesprochenes senden wir so, wie Sie es aufgenommen haben. Gebete erscheinen nicht an der ' +
        'Gebetswand und werden nicht wiederholt. Die Programmübersicht des Tages nennt bei Gebeten und Gebetsanliegen ' +
        'keine Namen.\n' +
        'Tippen Sie bei einem Anliegen an der Wand auf 🙏 („Ich habe mitgebetet“), sendet die App das mit ihrer ' +
        'nächsten Meldung. Damit jedes Gerät nur einmal zählt, speichern wir einen Wert, der aus Ihrer Gerätekennung ' +
        'und dem Anliegen mit einem geheimen Schlüssel gebildet ist und sich weder mit Ihren anderen Angaben noch mit ' +
        'Ihren übrigen Gebeten verbinden lässt; mit dem Antippen willigen Sie ausdrücklich ein, dass wir ihn dafür ' +
        'verarbeiten (Art. 9 Abs. 2 lit. a DSGVO). Wir löschen ihn, sobald das Anliegen nicht mehr an der Wand ' +
        'steht; nur die Zahl bleibt beim Anliegen und wird mit ihm gelöscht. Die Zahl sieht nur, wer das Anliegen ' +
        'geschickt hat; am Ende einer Gebetsstunde kann der Host sagen, wie oft insgesamt mitgebetet wurde.\n' +
        'Aufnahmen werden erst nach der Freigabe ' +
        'veröffentlicht und nur wiederholt, wenn Sie dem zugestimmt haben. Ein gewünschter Song oder ein ' +
        'vorgeschlagenes Video kann in unsere Auswahl aufgenommen werden – ohne Ihren Namen und ohne Ihre Widmung ' +
        'bzw. Begründung.\n' +
        'Abgelehnte Aufnahmen löschen wir sofort, ebenso Aufnahmen, die nicht mehr gesendet werden können; alle übrigen ' +
        'Einsendungen nach 90 Tagen. Aufnahmen, die Sie zur ' +
        'Wiederholung freigegeben haben, bleiben, bis Sie widersprechen oder Ihr Konto löschen. Ihre Einwilligungen ' +
        'können Sie jederzeit mit Wirkung für die Zukunft widerrufen – indem Sie Ihr Konto löschen oder per E-Mail ' +
        `an ${CONTACT}.`,
    },
    {
      h: 'KI-Dienste (OpenAI)',
      p:
        'Für die Prüfung der Einsendungen, die Moderationstexte und – außer bei Moderationen mit einer Stimme von ' +
        'ElevenLabs oder von unseren eigenen Rechnern (siehe unten) – ihre Stimme (auch beim Vorlesen von ' +
        'Gebetsanliegen und Gebeten) und die ' +
        'Verschriftlichung von Aufnahmen ' +
        'nutzen wir die Programmierschnittstelle der OpenAI Ireland Limited, 1st Floor, The Liffey Trust Centre, ' +
        '117–126 Sheriff Street Upper, Dublin 1, D01 YC43, Irland, die in unserem Auftrag tätig ist. Dabei ' +
        'übermitteln wir Ihren eingesandten Text bzw. Ihre Aufnahme und deren Abschrift, Vorname und Ort, bei ' +
        'Songwünschen und Videovorschlägen die Widmung bzw. Begründung und – für die Auswahl der Community-Stimmen – ' +
        'die betreffenden Chat-Nachrichten. ' +
        'Nach Angaben von OpenAI werden über die Schnittstelle übermittelte Daten nicht zum Training verwendet und bis ' +
        'zu 30 Tage zur Missbrauchserkennung gespeichert. Dabei können Daten in die USA übermittelt werden; ' +
        'Grundlage sind die Standardvertragsklauseln der EU-Kommission. Rechtsgrundlage sind Ihre Einwilligung zur ' +
        'Einsendung (Art. 6 Abs. 1 lit. a, Art. 9 Abs. 2 lit. a DSGVO) und unser berechtigtes Interesse an einem ' +
        'sicheren, moderierten Programm (Art. 6 Abs. 1 lit. f DSGVO). Mehr: https://openai.com/policies/privacy-policy',
    },
    {
      h: 'Wissen zu Liedern und Videos (OpenAI, Google)',
      p:
        'Damit die Prüfung beurteilen kann, was in einem Lied oder Video wirklich gesungen oder gesagt wird, und die ' +
        'Moderation erzählen kann, wovon es handelt, schlagen wir jedes Lied und Video einmal nach: Die Websuche von ' +
        'OpenAI (siehe oben) findet heraus, wer und was es ist, und Gemini, ein KI-Modell von Google (Google Cloud EMEA ' +
        'Limited, Dublin, Irland), sieht und hört sich das öffentliche YouTube-Video an. Dafür übermitteln wir nur den ' +
        'Link des Videos und seine öffentlichen Angaben bei YouTube (Titel, Kanal, Beschreibung, Stichwörter) – nie ' +
        'Ihren Namen, Ihren Ort, Ihre Widmung oder sonst etwas von Ihnen. Personenbezogene Daten von Ihnen werden dabei ' +
        'nicht verarbeitet. Nach Angaben von Google werden Anfragen und Antworten der kostenpflichtigen Schnittstelle ' +
        'nicht zur Verbesserung seiner Produkte verwendet. Mehr: https://ai.google.dev/gemini-api/terms',
    },
    {
      h: 'KI-Stimmen (ElevenLabs)',
      p:
        'Einzelne Moderationen sprechen mit einer Stimme von ElevenLabs; die Einsendeformulare sagen es, wenn das in der ' +
        'laufenden Sendung der Fall sein kann. Dafür übermitteln wir den Text, den die Moderation spricht, an die ' +
        'Programmierschnittstelle der Eleven Labs Inc., 169 Madison Ave #2484, New York, NY 10016, USA, die in unserem ' +
        'Auftrag tätig ist (in der EU: Eleven Labs Poland sp. z o.o., Lipska 27/22, 03-908 Warschau, Polen). Dieser Text ' +
        'kann Ihren Vornamen und Ort enthalten, bei Songwünschen und Videovorschlägen die Widmung bzw. Begründung, ' +
        'Gebetsanliegen und Gebete, die wörtlich vorgelesen werden, und Community-Stimmen. ElevenLabs bewahrt die ' +
        'erzeugten Texte und Aufnahmen im Verlauf unseres Kontos auf, bis wir sie dort löschen; der Nutzung zum ' +
        'Training haben wir widersprochen. Dabei werden Daten in die USA übermittelt; Grundlage ist die Zertifizierung ' +
        'von ElevenLabs nach dem EU-US Data Privacy Framework, ergänzend die Standardvertragsklauseln der ' +
        'EU-Kommission. Rechtsgrundlage sind Ihre Einwilligung zur Einsendung (Art. 6 Abs. 1 lit. a, Art. 9 Abs. 2 ' +
        'lit. a DSGVO) und unser berechtigtes Interesse an einem moderierten Programm (Art. 6 Abs. 1 lit. f DSGVO). ' +
        'Mehr: https://elevenlabs.io/privacy-policy',
    },
    {
      h: 'KI-Stimmen auf unseren eigenen Rechnern',
      p:
        'Einzelne Moderationen sprechen mit einer Stimme, die wir mit Qwen3-TTS, einem offenen Sprachmodell, auf ' +
        'unseren eigenen Rechnern erzeugen. Der Text, den die Moderation spricht, geht dafür verschlüsselt von unserem ' +
        'Server zu unserem Rechner und kommt als Aufnahme zurück; an einen fremden Dienst geht er nicht. Dieser Text ' +
        'kann Ihren Vornamen und Ort enthalten, bei Songwünschen und Videovorschlägen die Widmung bzw. Begründung, ' +
        'Gebetsanliegen und Gebete, die wörtlich vorgelesen werden, und Community-Stimmen. Der Rechner behält weder ' +
        'Text noch Aufnahme; auf unserem Server werden die Aufträge nach spätestens zwei Tagen gelöscht. ' +
        'Rechtsgrundlage sind Ihre Einwilligung zur Einsendung (Art. 6 Abs. 1 lit. a, Art. 9 Abs. 2 lit. a DSGVO) und ' +
        'unser berechtigtes Interesse an einem moderierten Programm (Art. 6 Abs. 1 lit. f DSGVO).',
    },
    {
      h: 'Community-Räume',
      p:
        'Die Räume laufen auf Servern der Hetzner Online GmbH in Deutschland, die wir bei Bedarf starten und bei ' +
        'Inaktivität wieder löschen. Für die Teilnahme verarbeiten wir Ihren Anzeigenamen, Ihr Land (falls angegeben), ' +
        'Ihre Nachrichten und Reaktionen. Anzeigenamen und Nachrichten prüft ein Wortfilter. Nachrichten liegen nur ' +
        'im Arbeitsspeicher des Raumservers (die letzten 100 je Raum) und verschwinden mit ihm. Viel geliebte ' +
        'Nachrichten können nach einer automatischen Prüfung bis zu zwei Stunden als „Stimme der Community“ auf der ' +
        'Startseite erscheinen, mit einem Kennzeichen, das aus der Kennung des Verfassers ' +
        'gebildet ist (damit, wer sie blockiert, auch ihre Stimmen nicht mehr sieht); wir löschen sie nach sieben Tagen.\n' +
        'Melden Sie eine Nachricht, eine Community-Stimme oder ein Gebetsanliegen, speichern wir den Inhalt, die ' +
        'Kennung des Verfassers (bei Gebetsanliegen das Anliegen) und Ihre Kennung; unsere ' +
        'Moderatoren prüfen jede Meldung innerhalb eines Tages. Ein Gebetsanliegen, das mehrere ' +
        'melden, nehmen wir bis zur Prüfung von der Wand. Meldungen löschen wir nach 30 Tagen. Wen Sie blockieren, ' +
        'speichert nur Ihr Gerät; wir erfahren davon als Meldung. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO ' +
        '(die Räume, die Sie nutzen) und lit. f (Schutz vor Missbrauch).',
    },
    {
      h: 'Passphrase',
      p:
        'Legen Sie freiwillig eine Passphrase an, erzeugt Ihr Gerät daraus Zugangsdaten; die 12 Wörter verlassen Ihr ' +
        'Gerät nie. Auf dem Server liegt nur ein Prüfwert (Argon2id). So nehmen Sie Ihre Identität auf ein anderes ' +
        'Gerät mit. Eine Wiederherstellung ist nicht möglich (Art. 6 Abs. 1 lit. b DSGVO). Wie Sie das Konto löschen, ' +
        'steht im nächsten Abschnitt.',
    },
    {
      h: 'Konto und Daten löschen',
      p:
        'Sie können Ihr Konto jederzeit selbst löschen: in der App bzw. auf der Website unter Profil › „Konto ' +
        'löschen“ oder auf https://radio.schaefchens.de/konto-loeschen – dort auch mit Ihren 12 Wörtern, wenn Sie ' +
        'die App nicht mehr haben. Sofort gelöscht werden Ihre Identität mit Name, Land und Passphrase auf allen ' +
        'Geräten, die sie nutzen; alles, was Sie eingesandt haben (auch Aufnahmen für Wiederholungen und Anliegen an ' +
        'der Gebetswand), auch aus dem geplanten Programm, sofern es noch nicht gesendet wurde; die Moderationstexte ' +
        'und -aufnahmen, die Sie nennen; Ihre Community-Stimmen und Meldungen über Ihre Beiträge; die Daten Ihres ' +
        'Geräts bei uns und auf dem Gerät.\n' +
        'Noch eine Weile bleiben: was schon in den veröffentlichten Programmdateien gesendet wurde (bis zu 48 Stunden), ' +
        'unsere Sicherungen (bis zu sieben Tage), Meldungen, die Sie selbst gemacht haben (ohne Ihre Kennung, bis zu ' +
        '30 Tage), und das Sicherheitsprotokoll mit Ihrer Kennung (bis zu 90 Tage). Gewünschte Songs können ohne ' +
        'Ihren Namen und Ihre Widmung in unserer Auswahl bleiben. Ohne Passphrase und ohne Ihr Gerät können wir ' +
        'Daten nicht Ihnen zuordnen: Gerätekennungen löschen wir dann 60 Tage nach der letzten Nutzung, ' +
        'Einsendungen nach 90 Tagen.',
    },
    {
      h: 'Datensicherung',
      p: 'Von der Datenbank legen wir täglich eine Sicherung an und bewahren die letzten sieben auf. Gelöschte Daten verschwinden daher spätestens nach einer Woche auch aus den Sicherungen.',
    },
    {
      h: 'Ihre Rechte',
      p:
        'Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, ' +
        'Datenübertragbarkeit und Widerspruch sowie das Recht, erteilte Einwilligungen zu widerrufen. Ihr Konto ' +
        'löschen Sie jederzeit selbst (siehe oben). Schreiben Sie ' +
        `uns dazu an ${CONTACT}. Beschwerden können Sie an die zuständige Aufsichtsbehörde richten: den ` +
        'Landesbeauftragten für den Datenschutz und die Informationsfreiheit Rheinland-Pfalz.',
    },
  ],
});

const en = (host: string): StationTexts => ({
  bindingNote: 'The imprint and privacy policy in English are provided for convenience; the German version is legally binding.',
  notice: 'Arche Radio is an independent project. Despite its name, it has nothing to do with the Arche church (Arche Gemeinde) in Hamburg.',
  about: [
    {
      h: 'One program. Many nations. One family.',
      p:
        'Arche Radio is a Christian community radio. Everyone who tunes in hears the same song, the same prayer and the ' +
        'same story at the same moment — wherever in the world they are.',
    },
    {
      h: 'Music, a host, a community',
      p:
        'The music comes from YouTube: worship songs chosen by our team and requested by listeners; preaching, ' +
        'testimony, mission and film programs add preachings, testimonies, videos from the mission field and Christian ' +
        `films. Between the songs ${host}, our host, speaks in English and German — about what is ` +
        'playing, your requests and your prayer requests.',
    },
    {
      h: 'Our hosts are AI',
      p:
        `Every host on Arche Radio, ${host} too, is an AI: their words are written by an AI model (from OpenAI) and ` +
        'spoken by a synthetic voice — from OpenAI or, for some hosts, from ElevenLabs or from Qwen3-TTS, an open ' +
        'speech model on our own computers. Our team decides which songs ' +
        'and programs run. Submissions are checked automatically before they go on air.',
    },
    {
      h: 'Take part',
      p:
        'Request a song, suggest a video — a preaching, a testimony, a mission video or a film —, share your story, a testimony or a greeting as a voice recording, or send a prayer request — ' +
        `${host} reads it out on air and invites everyone to pray for it. In the community rooms you can write with other listeners. Arche Radio needs no ` +
        'sign-up — no email, no password; if you like, a passphrase of 12 words takes your identity to another device. ' +
        'Arche Radio runs in the browser and as an app for iPhone and Android.',
    },
    {
      h: 'The prayer hour',
      p:
        'In the prayer hour listeners pray for one another: first we collect your prayer requests, then ' +
        `${host} reads them out word for word — first the daily prayer request of Open Doors for persecuted ` +
        'Christians (https://www.opendoors.de) — and then you send your prayers, spoken or written, and we air them. ' +
        `${host} never prays.`,
    },
    {
      h: 'A project of Schäfchens',
      p: `Arche Radio is a non-commercial project of Schäfchens (https://schaefchens.de). Questions and feedback: ${CONTACT}`,
    },
  ],
  imprint: [
    { h: 'Details pursuant to § 5 DDG', p: `${OPERATOR}\nGermany` },
    { h: 'Contact', p: `Email: ${CONTACT}` },
    { h: 'Responsible for content under § 18 (2) MStV', p: 'Christoph Scharf, address as above' },
    { h: 'VAT', p: 'Arche Radio is not offered commercially. No VAT is charged.' },
    {
      h: 'Online dispute resolution',
      p: 'We are neither obliged nor willing to take part in dispute resolution proceedings before a consumer arbitration board.',
    },
    { h: 'AI-generated content', p: "The hosts' words and voices are generated with artificial intelligence." },
    {
      h: 'Liability for content and links',
      p:
        'The content of this app is compiled with care. No guarantee can be given for accuracy, completeness or ' +
        "timeliness. Listeners' contributions express their own views. The videos (music, preachings, testimonies, mission videos and films) are embedded through the " +
        'YouTube player; their rights holders are responsible for their content. After some videos the stage links to ' +
        'the channels and websites of the preachers, churches, ministries and artists. The operators of linked external ' +
        'sites are responsible for their content; no legal violations were apparent at the time of linking.',
    },
    { h: 'Copyright', p: "Arche Radio's texts and design are protected by copyright. The rights to the music belong to its creators." },
  ],
  privacy: [
    { h: 'Controller', p: `${OPERATOR}, Germany\nEmail: ${CONTACT}` },
    {
      h: 'In short',
      p:
        'Arche Radio works without a sign-up (no email, no password), without advertising, without analytics or ' +
        'tracking and without cookies of its own. We process only what the radio, your submissions and the community ' +
        'rooms need. You can delete your account with everything you sent us yourself, at any time. Fonts come from ' +
        'our own server; the program, audio and images through the content delivery network BunnyCDN.',
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
      h: 'Arche Radio as an app (App Store, Google Play)',
      p:
        "You download the apps for iPhone and Android from Apple Distribution International Ltd.'s App Store or " +
        "Google Ireland Limited's Google Play, which process data under their own responsibility and terms. We only " +
        'receive aggregated figures from them (such as downloads and crashes), nothing about you.\n' +
        'The app opens radio.schaefchens.de in a window of its own: everything this policy says about the website ' +
        "applies to it, and “your browser's storage” means the app's storage there, which is deleted with the app. " +
        'The app contains no analytics or advertising services. The Android app excludes its data from backups and ' +
        'from transfers to a new device; your passphrase takes your identity along.\n' +
        'Your phone asks for permissions only when you use the feature: the microphone only for a recording you start ' +
        '(it stays on the device until you send it), the camera only for a photo moderators take for a program, ' +
        'notifications only for program reminders. Keeping the screen on while you listen is handled on the device ' +
        "and processes no data. You can withdraw any permission in your phone's settings.",
    },
    {
      h: 'Delivery through BunnyCDN',
      p:
        'Program files, audio and images are delivered through the content delivery network BunnyCDN of BunnyWay ' +
        'd.o.o., Dunajska cesta 165, 1000 Ljubljana, Slovenia, on our behalf (Art. 28 GDPR). Bunny runs ' +
        "servers around the world; your app's requests are usually answered by one near you, which processes your " +
        'IP address to do so. Bunny keeps no IP addresses in its access logs — we set it up that way — but it does ' +
        'keep the time, the file requested, country and network operator, browser, referring page and a technical ' +
        'identifier of the connection, and deletes the logs after three days. From them we only count how often the ' +
        'program file of each minute was fetched: that tells us how many are listening without recognising anyone. ' +
        'Audio and images we delete are also removed from the caches of the network. Legal basis: Art. 6(1)(f) GDPR, ' +
        'fast and reliable delivery to listeners all over the world. More: https://bunny.net/privacy/',
    },
    {
      h: 'Device id and storage on your device',
      p:
        "On first start the app stores a random device id with a secret in your browser's or the app's storage (not a cookie). It " +
        'lets the server recognise your device: for the status of your submissions, your reactions, protection ' +
        'against abuse and counting listeners. The server keeps only values derived with a secret key (HMAC). The app ' +
        'also stores your settings locally (language, volume, channel, theme, your YouTube consent, your acceptance of ' +
        'the community rules), which reactions you gave (so they stay pressed), whom you blocked and what you reported ' +
        '(so it stays hidden), your program reminders, program files and audio of the last few days for a weak ' +
        'connection and — only if you create one — your passphrase. “Delete data on this device” in the privacy ' +
        'settings removes all of it.\n' +
        'This storage is strictly necessary for the service you use (§ 25(2) no. 2 TDDDG); the legal basis is ' +
        'Art. 6(1)(f) GDPR. While you listen, the app reports every two minutes that your device is there; we delete ' +
        'these entries after one day, abuse-protection entries (with a hashed IP address) after two days, error codes ' +
        "of the YouTube player (which device could not play which video) after 30 days, and device ids without " +
        'submissions or a passphrase after 60 days without use.',
    },
    {
      h: 'Program reminders (apps only)',
      p:
        'In the apps you can ask to be reminded of a program. Which programs you chose is stored only on your device; ' +
        "your phone's operating system schedules the reminders as local notifications — about five minutes before the " +
        'start, for the coming week, renewed whenever you open the app. For this the app sends nothing to us and uses ' +
        'no push service of Apple or Google; the times come from the program plan the app loads anyway. Your phone ' +
        'asks for permission to show notifications only when you first tap “Remind me”. “Delete data on this device” ' +
        'also removes all scheduled reminders. Legal basis: § 25(2) no. 2 TDDDG.',
    },
    {
      h: 'YouTube',
      p:
        'The videos (music, preachings, testimonies, mission videos and films) are embedded in privacy-enhanced mode (youtube-nocookie.com), a service of Google Ireland ' +
        'Limited, Gordon House, Barrow Street, Dublin 4, Ireland. The player loads only when you tap “Tap to join ' +
        'live” — before that, the app makes no connection to Google. By tapping you consent that Google processes ' +
        'your IP address, information about your device and the videos played, and may store information on your ' +
        'device (Art. 6(1)(a) GDPR, § 25(1) TDDDG). The same applies to the preview of a requested song or a suggested video. Google may ' +
        'transfer data to the USA; Google LLC is certified under the EU-US Data Privacy Framework. You can withdraw ' +
        'your consent at any time under “Privacy settings” below.\n' +
        'Arche Radio uses the YouTube API Services: the embedded player and, on our server, the YouTube Data API, to ' +
        'which we send only video ids to check title, length and whether a video can be played; we serve thumbnails ' +
        "from our own server. YouTube's Terms of Service (https://www.youtube.com/t/terms) and Google's Privacy " +
        'Policy (https://policies.google.com/privacy) apply.',
    },
    {
      h: 'Submissions: song requests, video suggestions, recordings, prayer requests, prayers',
      p:
        'When you request a song, suggest a video (a preaching, a testimony, a mission video or a film), send a voice recording or a prayer request, or speak or write a ' +
        'prayer in a prayer hour\'s prayer time, we process what you ' +
        'give us (first name, place, your text or recording, for a song request or a video suggestion the link and ' +
        'a dedication or your reason) together with your device ' +
        'id, to check and air it. The legal basis is your consent (Art. 6(1)(a) GDPR). Prayer requests, prayers, testimonies ' +
        'and stories can reveal religious beliefs or health information; by sending, you explicitly consent to our ' +
        'processing them for this purpose (Art. 9(2)(a) GDPR).\n' +
        'On air we name you only by the first name and place you give. With every submission you can tick “Stay ' +
        'anonymous”; then we ask for neither and name nobody. A typed prayer request is read out word for word, ' +
        'and the app shows the text meanwhile. It also appears on the app’s prayer wall after approval if you tick ' +
        'that box (it is not ticked in advance): the text, the day and — unless you stay anonymous — your first name ' +
        'and place. The wall shows the newest requests; yours stays there until newer ones push it out, a moderator ' +
        'takes it down or we delete it after 90 days. While it is read out on air, the app marks it on the wall as the ' +
        'one on air now. A request taken down is no longer read out.\n' +
        'In a prayer hour the wall shows that hour\'s requests: each one from the moment it is read out until the hour ' +
        'ends — ticked or not, and as everywhere with your first name and place unless you stay anonymous; there the ' +
        'box decides whether your request stays on the prayer wall afterwards. A prayer you send in its prayer time is ' +
        'read out word for word, with your first name and place unless you stay anonymous; a spoken one is aired as ' +
        'you recorded it. Prayers do not appear on the prayer wall and are never replayed. The day\'s program list ' +
        'names nobody for prayers and prayer requests.\n' +
        'When you tap 🙏 (“I prayed”) on a request on the wall, the app sends it with its next report. So that each ' +
        'device counts once, we store a value derived from your device id and the request with a secret key, which ' +
        'cannot be linked to your other data or to your other prayers; by tapping you explicitly consent to our ' +
        'processing it for this (Art. 9(2)(a) GDPR). We delete it as soon as the request is no longer on the wall; ' +
        'only the number stays with the request and is deleted with it. Only the person who sent the request sees ' +
        'the number; at the end of a prayer hour the host may say how often listeners prayed along.\n' +
        'Recordings are published only after approval and replayed only with your ' +
        'consent. A requested song or a suggested video may join our selection — without your name and without ' +
        'your dedication or reason.\n' +
        'We delete rejected recordings immediately, and recordings that can no longer be aired; all other submissions ' +
        'after 90 days. Recordings you allowed ' +
        'to be replayed stay until you object or delete your account. You can withdraw your consent at any time, for ' +
        `the future — by deleting your account or by email to ${CONTACT}.`,
    },
    {
      h: 'AI services (OpenAI)',
      p:
        "To check submissions, for the hosts' words and — except for hosts with a voice from ElevenLabs or from our own " +
        'computers (see below) — ' +
        'their voice (also when they read out prayer requests and prayers) and to transcribe recordings we use the API of OpenAI ' +
        'Ireland Limited, 1st Floor, The Liffey Trust Centre, 117–126 Sheriff Street Upper, Dublin 1, D01 YC43, ' +
        'Ireland, on our behalf. We send your text or recording and its transcript, first name and place, for song ' +
        'requests and video suggestions the dedication or reason and — to select community voices — the chat ' +
        'messages concerned. According to ' +
        'OpenAI, data sent through the API is not used for training and is stored for up to 30 days to detect abuse. ' +
        "Data may be transferred to the USA on the basis of the EU Commission's standard contractual clauses. Legal " +
        'basis: your consent to the submission (Art. 6(1)(a), Art. 9(2)(a) GDPR) and our legitimate interest in a ' +
        'safe, moderated program (Art. 6(1)(f) GDPR). More: https://openai.com/policies/privacy-policy',
    },
    {
      h: 'Knowing our songs and videos (OpenAI, Google)',
      p:
        'So that the check can judge what is really sung or said in a song or video, and the hosts can tell what it is ' +
        "about, we look each song and video up once: OpenAI's web search (see above) finds out who and what it is, and " +
        'Gemini, an AI model by Google (Google Cloud EMEA Limited, Dublin, Ireland), watches and listens to the public ' +
        "YouTube video. For this we send only the video's link and its public details on YouTube (title, channel, " +
        'description, tags) — never your name, your place, your dedication or anything else of yours. No personal data of ' +
        "yours is processed. According to Google, requests and answers of its paid API are not used to improve its " +
        'products. More: https://ai.google.dev/gemini-api/terms',
    },
    {
      h: 'AI voices (ElevenLabs)',
      p:
        'Some hosts speak with a voice from ElevenLabs; the submission forms say so when that can be the case in the ' +
        'program on air. For it we send the text the host speaks to the API of Eleven Labs Inc., 169 Madison Ave #2484, ' +
        'New York, NY 10016, USA, on our behalf (in the EU: Eleven Labs Poland sp. z o.o., Lipska 27/22, 03-908 Warsaw, ' +
        'Poland). This text can contain your first name and place, for song requests and video suggestions the ' +
        'dedication or reason, prayer requests and prayers read out word for word, and community voices. ElevenLabs ' +
        "keeps the generated texts and recordings in our account's history until we delete them there; we have " +
        'objected to their use for training. Data is transferred to the USA on the basis of ElevenLabs\' certification ' +
        "under the EU-US Data Privacy Framework, with the EU Commission's standard contractual clauses in addition. " +
        'Legal basis: your consent to the submission (Art. 6(1)(a), Art. 9(2)(a) GDPR) and our legitimate interest in a ' +
        'moderated program (Art. 6(1)(f) GDPR). More: https://elevenlabs.io/privacy-policy',
    },
    {
      h: 'AI voices on our own computers',
      p:
        'Some hosts speak with a voice we generate with Qwen3-TTS, an open speech model, on our own computers. For it ' +
        'the text the host speaks goes encrypted from our server to our computer and comes back as a recording; it ' +
        'goes to no outside service. This text can contain your first name and place, for song requests and video ' +
        'suggestions the dedication or reason, prayer requests and prayers read out word for word, and community ' +
        'voices. The computer keeps neither the text nor the recording; on our server the jobs are deleted after two ' +
        'days at the latest. Legal basis: your consent to the submission (Art. 6(1)(a), Art. 9(2)(a) GDPR) and our ' +
        'legitimate interest in a moderated program (Art. 6(1)(f) GDPR).',
    },
    {
      h: 'Community rooms',
      p:
        'The rooms run on servers of Hetzner Online GmbH in Germany, started when needed and deleted when idle. To take ' +
        'part we process your display name, your country (if given), your messages and reactions. A word filter ' +
        "checks display names and messages. Messages exist only in the room server's memory (the last 100 per room) " +
        'and disappear with it. Much-loved messages may appear, after an automated check, as a “community voice” on ' +
        "the home screen for up to two hours, with a mark derived from their author's id (so that whoever blocked " +
        'them does not see their voices either); we delete them after seven days.\n' +
        'When you report a message, a community voice or a prayer request, we store the content, its author\'s id ' +
        '(for a prayer request, the request) and your id; our moderators check every report within a day. A prayer ' +
        'request reported by several listeners comes off the wall until it is checked. We delete reports after 30 ' +
        'days. Whom you block is stored only on your device; we learn of it as a report. Legal basis: Art. 6(1)(b) ' +
        'GDPR (the rooms you use) and (f) (protection against abuse).',
    },
    {
      h: 'Passphrase',
      p:
        'If you choose to create a passphrase, your device derives credentials from it; the 12 words never leave your ' +
        'device. The server keeps only a check value (Argon2id). This lets you take your identity to another device. ' +
        'It cannot be recovered (Art. 6(1)(b) GDPR). How to delete the account: see the next section.',
    },
    {
      h: 'Deleting your account and data',
      p:
        'You can delete your account yourself at any time: in the app or on the website under Profile › “Delete ' +
        'account”, or at https://radio.schaefchens.de/konto-loeschen — there also with your 12 words if you no ' +
        'longer have the app. Deleted at once: your identity with name, country and passphrase on every device ' +
        'that uses it; everything you sent (including recordings kept for replays and requests on the prayer ' +
        "wall), also from the planned program if it has not aired yet; the host's words and recordings that name " +
        "you; your community voices and reports about your posts; your device's data with us and on the device.\n" +
        'Kept for a while: what already went out in the published program files (up to 48 hours), our backups (up to ' +
        'seven days), reports you filed yourself (without your id, up to 30 days) and the security log with your id ' +
        '(up to 90 days). Requested songs may stay in our selection without your name or dedication. Without your ' +
        'passphrase and your device we cannot tell which data is yours: device ids are then deleted 60 days after ' +
        'their last use, submissions after 90 days.',
    },
    {
      h: 'Backups',
      p: 'We back up the database daily and keep the last seven copies, so deleted data disappears from the backups within a week.',
    },
    {
      h: 'Your rights',
      p:
        'You have the right of access, rectification, erasure, restriction of processing, data portability and ' +
        'objection, and the right to withdraw consent. You can delete your account yourself at any time (see above). ' +
        `Write to us at ${CONTACT}. You can lodge a complaint with the ` +
        'competent supervisory authority: the State Commissioner for Data Protection and Freedom of Information of ' +
        'Rhineland-Palatinate.',
    },
  ],
});

export function stationTexts(lang: Lang, hostName: string): StationTexts {
  return lang === 'de' ? de(hostName) : en(hostName);
}
