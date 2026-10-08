# Arche Radio in the App Store and on Google Play

What the two stores ask for, derived from what this code does. How to build
and upload: [README.md](README.md), "Store apps". Why the apps are a frame
around the live site: [CLAUDE.md](CLAUDE.md), "Native apps".

Keep this file in step with the code and with the privacy policy
(`app/src/content/legal.ts`): the answers below must stay true.

## Identity

| | |
|---|---|
| App ID / package | `de.schaefchens.apps.archeradio` (permanent) |
| Name | Arche Radio |
| Subtitle (30) | en: Christian community radio · de: Christliches Community-Radio |
| Category | Music & Audio (Play) · Music (App Store; secondary: Lifestyle) |
| Devices | iPhone only (runs on iPad in compatibility mode); Android phones |
| Privacy policy | https://radio.schaefchens.de/datenschutz |
| Account deletion (Google) | https://radio.schaefchens.de/delete-account (= /konto-loeschen) |
| Terms / community rules | https://radio.schaefchens.de/regeln |
| Support | https://radio.schaefchens.de/about · app.support@schaefchens.de |
| Copyright | © Christoph Scharf (Schäfchens) |

## Listing texts

**Keywords (Apple, 100 characters)** — en:
`christian,radio,worship,praise,prayer,gospel,church,bible,community,music,live` ·
de: `christlich,radio,lobpreis,gebet,worship,gospel,kirche,gemeinde,bibel,musik,live`

**Promotional text** — en: One program. Many nations. One family. Worship
songs, prayers and stories from listeners around the world, live. · de: Ein
Programm. Viele Nationen. Eine Familie. Lobpreis, Gebete und Geschichten von
Hörern aus aller Welt – live.

**Description (en)**

> Arche Radio is a Christian community radio: one program that everyone hears
> at the same moment — worship songs, prayers and stories from listeners
> around the world.
>
> • Live together: everyone who tunes in hears the same song at the same second.
> • Your voice on air: request a song, suggest a preaching, record a story, a
>   testimony or a greeting, or send a prayer request — our host reads it out
>   and invites everyone to pray.
> • The prayer wall: pray along with other listeners' requests.
> • Prayer hours every morning and evening, where listeners pray for one
>   another — spoken or written — and preaching programs.
> • Community rooms: write with other listeners while you listen.
> • Program reminders: a notification when a program you like begins.
> • In English and German. No sign-up, no tracking, and no ads of our own.
>
> The music plays through YouTube's official player, where YouTube may show
> its own ads; keep the app open while
> you listen. Our host is an AI voice. Arche Radio is a non-commercial project
> of Schäfchens.

**Beschreibung (de)**

> Arche Radio ist ein christliches Community-Radio: ein Programm, das alle im
> selben Moment hören – Lobpreis, Gebete und Geschichten von Hörern aus aller
> Welt.
>
> • Gemeinsam live: Alle, die einschalten, hören in derselben Sekunde denselben Song.
> • Deine Stimme auf Sendung: Wünsch dir einen Song, empfiehl eine Predigt,
>   nimm eine Geschichte, ein Zeugnis oder einen Gruß auf oder schick ein
>   Gebetsanliegen – unser Host liest es vor und lädt alle zum Mitbeten ein.
> • Die Gebetswand: Bete für die Anliegen anderer mit.
> • Gebetsstunden jeden Morgen und Abend, in denen Hörer
>   füreinander beten – gesprochen oder geschrieben –, dazu Predigtsendungen.
> • Community-Räume: Schreib mit anderen, während ihr zuhört.
> • Sendungs-Erinnerungen: eine Mitteilung, wenn eine Sendung beginnt, die du magst.
> • Auf Deutsch und Englisch. Ohne Anmeldung, ohne Tracking und ohne eigene Werbung.
>
> Die Musik läuft über den offiziellen Player von YouTube, wo YouTube eigene
> Werbung zeigen kann; lass die App beim
> Zuhören geöffnet. Unser Host ist eine KI-Stimme. Arche Radio ist ein
> nicht-kommerzielles Projekt von Schäfchens.

**Screenshots**: iPhone 6.9" (iOS 26 simulator, iPhone 17 Pro Max) and Android
phone, both themes: Home with the stage, the prayer wall, the schedule with a
reminder, a submit sheet, a community room. Google Play also wants a 512 × 512
icon (`app/native/assets/icon-only.svg`) and a 1024 × 500 feature graphic.

## App Store: App Privacy

No tracking (no ATT prompt). Everything below is **linked to the user** (the
device id or passphrase account) and used for **App Functionality** only,
unless noted.

| Apple data type | Collected | What it is |
|---|---|---|
| Contact Info › Name | optional | display name in the rooms; first name on submissions |
| User Content › Audio Data | optional | voice recordings sent for the program |
| User Content › Other User Content | optional | chat messages, prayer requests, dedications, links, place, reports |
| Sensitive Info | optional | religious beliefs in prayers and testimonies |
| Health & Fitness › Health | optional | health details listeners write into prayer requests |
| Identifiers › User ID | yes | the app-generated device id / passphrase account (also abuse protection) |
| Usage Data › Product Interaction | yes | presence pulses, reactions, praying along — also **Analytics** (the listener count) |
| Diagnostics › Other Diagnostic Data | yes | YouTube player error codes (which device could not play which video) |

Not collected: location (place and country are typed content), contacts,
financial info, browsing or search history, purchases, e-mail address, crash
logs, advertising data.

## Google Play: Data safety

- **Collected**: Personal info › Name (optional); Personal info › Religious
  beliefs (optional); Health info (optional); Messages › Other in-app messages
  (optional); Audio › Voice recordings (optional); App activity › App
  interactions (required while listening) and Other user-generated content
  (optional); App info and performance › Diagnostics (required); Device or
  other IDs (required).
- **Purposes**: App functionality; Analytics (listener count); Fraud
  prevention, security and compliance; Account management.
- **Shared**: none. OpenAI, ElevenLabs (the voice of some hosts), Hetzner and BunnyCDN process data on our behalf
  (processors, not sharing). YouTube is loaded only after the listener's own
  tap to join.
- **Encrypted in transit**: yes. **Deletion**: yes, in the app (Profile ›
  Delete account) and at https://radio.schaefchens.de/konto-loeschen.
- **Account creation**: yes — an anonymous device id, optionally a passphrase.

## Google Play: what was entered (2026-10-04)

Play Console app id 4972697494700123670 (account "Christoph Scharf", personal).
Default listing en-US, translation de-DE; short descriptions "Christian
community radio: worship, prayers and stories from listeners, live" / "Christliches
Community-Radio: Lobpreis, Gebete und Geschichten, live". Graphics: the icon
from `native/assets/icon-only.svg`, the feature graphic from the light scenery
and logo, screenshots of the live site at 1080×1920 (song thumbnails hidden) —
all labelled AI-generated, as the art is. Ads: none of our own (YouTube's ads inside its player are not
an ad SDK). Sign-in: none needed (/mod is staff-only). Target age 16–17 and
18+. IARC: Everyone / PEGI 3 / USK 0, "Users interact". Data safety as below,
account creation "Other" (anonymous device account, optional 12 words).
Upload key: `~/keys/arche-radio-upload.keystore` (alias `arche`), password in
`app/android/keystore.properties` — back both up off this Mac.

## Age rating

User-generated content: yes (rooms, prayer wall, submissions), moderated.
Messaging between users: yes. No unrestricted web access (other sites open in
the system browser). No mature themes, no gambling, no purchases. The
community rules say posting is from 16 (younger with a parent's OK); the
rating the questionnaire computes may be lower — choose 16+ if the store page
should say the same.

## Review notes (paste into App Store Connect / Play Console)

> Arche Radio is a live Christian community radio: one program everyone hears
> at the same moment. Tap "Tap to join live" on the stage to start. No account
> or login is needed.
>
> Music videos and preachings play through YouTube's official IFrame player,
> as YouTube requires: nothing covers the player, it is at least 200×200, it
> plays only while visible, and nothing plays in the background (the app
> stops the radio when it goes to the background; it declares no background
> audio mode).
>
> User-generated content and safety: chat rooms (menu › Chat), prayer
> requests on the prayer wall, song requests and recordings. Every submission
> is checked automatically before it airs; a word filter covers chat messages
> and display names. Report: the flag on any chat message, community voice or
> prayer request. Block: "Block" on a chat message (unblock in Profile).
> Reports reach our moderators, who check them daily. Community rules:
> https://radio.schaefchens.de/regeln (accepted before the first post).
>
> Account deletion: Profile › Delete account (also on the web:
> https://radio.schaefchens.de/konto-loeschen).
>
> Native features: program reminders as local notifications (Schedule › a
> program › Remind me), sharing, haptics, the screen kept on while listening,
> Android back navigation, an offline screen.
>
> The first visit to a chat room may take 30–40 seconds while a room server
> starts; during review we keep one running.

## Before submitting

- [ ] The web part is deployed (`npm run deploy`) — the shell loads it.
- [ ] Moderators have filled the word filter in /mod › Chat (an empty list filters nothing).
- [ ] The realtime snapshot is rebuilt (`npm run realtime:snapshot`), so deleted accounts leave the rooms.
- [ ] Someone checks /mod › Review and /mod › Chat **every day, weekends too**: the stores require reports to be handled within 24 hours.
- [ ] A chat room is kept warm while a review is pending (open /chat now and then).
- [ ] TestFlight / Play internal testing on real phones: the checklist in the README.
- [ ] Google Play, personal developer account: a closed test with ≥ 12 testers for 14 days before production.
