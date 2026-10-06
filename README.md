# ARCHE

One program. Many nations. One family. — a Christian community radio as a PWA.
Architecture and the reasons behind it: [CLAUDE.md](CLAUDE.md). Concept:
[CONCEPT.md](CONCEPT.md), [CONCEPT-TECHNICAL.md](CONCEPT-TECHNICAL.md).

## Run it locally

Needs Docker, Node ≥ 24 and npm.

```bash
npm install
npm run init-secrets                       # appends generated keys to .env (never overwrites)
npm run composer -- install                # PHP deps into server/vendor (inside Docker)
bash scripts/assemble-site.sh --dev        # web root for the stack in .data/site
npm run stack                              # Apache+PHP-FPM :8080, cron loop, realtime :8787
npm run dev                                # the PWA with hot reload → http://localhost:5180
```

The station's database, uploads and backups live in the Docker volume
`arche_var` (not in `.data/site`: SQLite's WAL does not work on a macOS bind
mount). `npm run stack:down` keeps it; `docker volume rm arche_var` starts the
local station over. A look inside:
`docker compose --env-file docker/compose.env exec php sqlite3 /var/www/site/_arche/var/arche.sqlite`.

The stack runs with `AI_MODE=stub` (no paid calls). For the real host voice:
`AI_MODE=live npm run stack` (an OpenAI key is enough: it writes, voices,
transcribes and moderates; with `ANTHROPIC_KEY` set, Claude writes and
moderates instead). Costs are capped by `AI_DAILY_BUDGET_USD`; while nobody
listens, the host voices only what listeners sent (announced requests,
recordings, prayer requests and prayers read out) and a prayer hour's order
(welcome, presentation, the prayer time's announcement), whose welcome is
written before its listeners tune in. Open Doors' daily prayer request is
fetched hourly (`OPENDOORS_FEED_URL`; `off` turns it off). One real
host break without touching the program: see `server/bin/try-host.php`.

First admin, exactly as in production: open http://localhost:5180/profile,
create a passphrase, then open /setup and enter `ADMIN_SETUP_KEY` from `.env`.
Then build programs, plans and the song library in /mod.

## Test

```bash
npm run verify          # typecheck + lint + all JS tests + PHP tests
npm test                # shared, app, realtime (Vitest)
npm run test:php        # server tests in the PHP 8.5 container
npm run test:php -- "prayer hour"   # only the tests whose name contains this
npm run e2e             # Playwright against a separate e2e stack (:8090), see below
```

`npm run e2e` builds the PWA and starts its own Docker project (`arche-e2e`,
web :8090, realtime :8797) on `.data/e2e`, with stub AI, a fake YouTube and no
real keys — the dev stack and its data are not touched. The first run on a
fresh e2e station waits a minute or two until the program is on air; the
prayer hour spec opens a channel of its own and waits a few more minutes for
its prayer music. The e2e stack makes no outside calls (`OPENDOORS_FEED_URL=off`).
`npm run e2e:down` stops it, `npm run e2e:reset` deletes its data. Needs the
Playwright browser once: `npx playwright install chromium`.

## Deploy (Hetzner Webhosting S)

1. **Probe the host first** (once): `bash scripts/probe/probe.sh --upload`, add the
   printed cron line in konsoleH, wait ~24 h, `--read`, then `--remove`. It tells
   the real cron interval (production's runs every minute), how long PHP may run after a request (→ `TICK_BUDGET`),
   whether SQLite WAL works (→ `SQLITE_WAL`) and that every `.htaccess` directive
   is allowed. Details: [scripts/probe/README.md](scripts/probe/README.md).
2. `npm run deploy:dry` — what would be uploaded.
3. `npm run deploy -- --initial` — first install: also uploads a server `.env`
   built from an allow-list of `.env` keys (never the SFTP ones). Later deploys:
   `npm run deploy` (only changed files; `--env` replaces the server `.env`).
4. konsoleH cron, every minute: the line from `npm run cron-command`.
5. Open `/profile` → create a passphrase → `/setup` with `ADMIN_SETUP_KEY`.
6. `npm run deploy:verify` checks the live site (403 on private files, Bearer auth
   reaches PHP, caching, gzip, SPA fallback, …).

## Realtime (chat, presence, reactions) on Hetzner Cloud

Scale-to-zero nodes, created from a snapshot when someone opens a room and
deleted when idle. Needs `HETZNER_CLOUD_TOKEN` in `.env`.

```bash
npm run realtime:setup -- --slots 1          # Primary IPs, 10 GB Volume, firewall; prints DNS + .env lines
npm run realtime:snapshot                    # builds arche-realtime for the node type and snapshots a node image
```

Add the printed DNS records (`rt1.radio.schaefchens.de`), put the printed
`REALTIME_*` lines plus `REALTIME_DRIVER=hcloud` and `REALTIME_ACME_EMAIL` in
`.env`, then `npm run deploy -- --env`. Rebuild the snapshot after changing
`realtime/` or `infra/realtime/`. `REALTIME_SSH_KEY` (the id or name of an SSH
key in the Hetzner project) goes on every node: without one, Hetzner mails a
root password for each node it creates.

Live since 2026-09-24: slot `rt1` in fsn1, nodes on **cpx12** (x86) with
**cpx22** as the fallback when Hetzner refuses the first type — Arm (`cax*`)
was sold out in every location that day. The snapshot must match the node
architecture: `npm run realtime:snapshot -- --type cpx12` (the default); it
says which types a location can create when the one asked for cannot be. A
node costs about €0.02 per hour while a room is open, is deleted after 10
minutes without anyone and after 12 hours at the latest. The very first start
(or one after ~90 days without any) waits for its Let's Encrypt certificate,
which then stays on the slot's volume; the app's reconnect covers the wait.

## CDN (BunnyCDN)

A pull zone in front of `/program` and `/media`: the edge fetches each file
once from the site and keeps it as long as the file's Cache-Control says
(minute files and media for good, `live.json` 15 s, a 404 never). Nothing is
uploaded, so the tick never waits for the CDN, and the app reads from the site
whenever the edge fails (a network error, a timeout, any answer but the file
or a 404) and skips the edge for five minutes. Needs `BUNNY_API_KEY` in `.env`.

```bash
npm run cdn:setup                            # creates or updates the zone; prints CDN_BASE_URL + BUNNY_PULL_ZONE_ID
```

Put the two printed lines in `.env`, then `npm run deploy -- --env`: the
server tells the app about the CDN (`/api/session`, remembered by the app),
and the page's CSP allows it (the deploy assembles with `--cdn`). The tick
then also purges deleted media at the edge and counts listeners from the
zone's log, which keeps no IP addresses: the requests for one minute's file
are that minute's audience (the listener count is the highest of pulses, room
presence and this). `CDN_BASE_URL=off` switches it off; the local stacks never
use the production zone (the e2e stack has a stand-in on port 8091).

Live since 2026-09-24: zone `arche-radio` (id 6679436) at
`https://arche-radio.b-cdn.net`.

## Store apps (iOS, Android)

Capacitor 8 shells around the live site: the app opens
https://radio.schaefchens.de in a native frame (`app/capacitor.config.ts`), so
every deploy reaches the apps at once and only native changes need a store
release. Why it does not ship its own copy, and the rules for code that talks
to the shell: [CLAUDE.md](CLAUDE.md), "Native apps". Store texts, privacy
answers and review notes: [STORE.md](STORE.md).

Needs Xcode 26 (iOS) and, for Android, the SDK in `~/Library/Android/sdk` plus
JDK 21 (`brew install openjdk@21`; `scripts/native/android-env.sh` finds both).

```bash
npm run native:sync                         # after npm install or a plugin change: copies the config, links the plugins
npm run native:ios                          # build and run in a simulator (asks which)
npm run native:android                      # build and run in the emulator / a phone
npm --workspace @arche/app run native:ios:xcode   # open the Xcode project (archive, signing)
npm run native:assets                       # icons and splash from app/native/assets/*.svg
npm run native:version -- 1.1.0             # both stores' version + one shared build number
npm run native:aab                          # the signed Android App Bundle for Play
```

Against a local stack instead of the live site:
`npx cap run ios -l --host localhost --port 5180` (the dev server; Android adds
`--forwardPorts 5180:5180`), or port 8090 for the e2e stack's production build
with its CSP. Release builds refuse such a config (a check in the Xcode build
and in Gradle), so run `npm run native:sync` before archiving.

**First release, Apple**

1. A paid Apple Developer account. In Xcode (`native:ios:xcode`) › App ›
   Signing & Capabilities, pick the team with automatic signing: it registers
   the App ID `de.schaefchens.apps.archeradio` and creates the distribution
   certificate on the first archive.
2. App Store Connect › New App: iOS, name "Arche Radio", primary language,
   bundle id, SKU. Fill in what STORE.md lists (privacy URL, App Privacy,
   age rating, review notes); iPhone only, Mac and Apple Vision availability off.
3. Product › Archive › Distribute App › App Store Connect; test it in
   TestFlight (internal testers need no review), then submit.

**First release, Google Play**

1. Create the upload key once (`app/android/keystore.properties.example` says
   how) and **back it up off this Mac** with its passwords.
2. Play Console › Create app; the "App content" forms from STORE.md (Data
   safety, content rating, target audience — not the Families program).
3. `npm run native:aab`, then upload
   `app/android/app/build/outputs/bundle/release/app-release.aab` to internal
   testing (Play App Signing is set up with it). A personal developer account
   needs a closed test with at least 12 testers for 14 days before production.

**Every release**

- Web changes: `npm run deploy` — nothing to do in the stores.
- Shell changes (native code, plugins, icons, `capacitor.config.ts`):
  `npm run native:version -- x.y.z`, `npm run native:sync`, archive and upload
  in Xcode, `npm run native:aab` and upload; commit "Release the apps x.y.z
  (build n)". The web part a new shell relies on is deployed first.
- After changing `realtime/`, rebuild the node snapshot
  (`npm run realtime:snapshot`): deleted accounts leave the rooms only on
  nodes that know `forget`.

**On real phones** (simulator and emulator first): starts with the splash,
never stuck; offline at start → the offline page, which comes back by itself;
YouTube plays inline (no error 150/153) and the next songs follow without a
tap; host clips audible with the silent switch on (iPhone); home button or
lock → silence within a second and the hint on return, Control Center does
not stop it; the screen stays on while listening; a recording asks for the
microphone in the phone's language and the radio sounds as before afterwards;
safe areas (Dynamic Island, home indicator, both themes); Android back closes
sheets, then goes to Home, then to the background; a program reminder arrives
about five minutes early and opens its channel (Android: on time only after
"Allow on-time reminders"; without it, minutes late); share sheet; a vibration on
reactions; e-mail and "Open on YouTube" leave the app; the update banner after
a deploy.

## Operate

- **/mod → Status**: last tick, how far ahead each channel is committed, jobs,
  host-break outcomes, AI spend vs budget, realtime nodes, audit log.
- **Costs**: `AI_DAILY_BUDGET_USD` (all AI), `HOST_MAX_BREAKS_PER_DAY`
  (300; people's words read out do not count), `HOST_MIN_LISTENERS`,
  `MODERATION_MAX_PER_DAY`, and each host's characters per day.
- **Hosts**: /mod → Hosts (admins). Each has a picture, name, colour, a few
  words for listeners, style notes for the writer, and its voice: OpenAI or
  ElevenLabs with its own key (write-only; an OpenAI host without one uses
  `OPENAI_KEY`), model, voice, direction, settings and a daily character
  limit — an ElevenLabs host without one never speaks. "Try voice" plays a
  sample with unsaved changes (it spends characters). Programs (and channels,
  for programs without their own) pick their hosts in their editor: on air —
  one per show, at random — and fallbacks in order. A host that fails rests
  (until the next day for a quota or a wrong key; saving it tries again); the
  next one steps in. /mod → Status lists every host's state. Before an
  ElevenLabs host goes on air: turn off the use of data for training in the
  ElevenLabs account (the privacy policy says so).
- **Backups**: a daily `VACUUM INTO` copy in `/_arche/var/backups` (seven kept).
- **Pull from air**: /mod → Library → pull; clients skip it within a minute.
- **Requests** air about ten minutes after approval; several waiting are
  presented together by the host (three at most, then two regular songs).
  Intake closes 15 minutes before a program ends ("last chance" from 25; per
  program in /mod → Programs). Too late for its program, a song stays in the
  music selection ("may play in a later program"); anything else is marked as
  missed, and a recording that never aired is not kept public.
- **Names**: every submission form asks "Stay anonymous". Unticked (the
  default) a first name is needed, and the host says it with the place (a
  prayer request also shows both on the prayer wall); ticked, neither is sent
  and nobody is named.
- **Rejections**: /mod → Review → Rejected shows why each submission was
  declined (the automatic check's verdict and note, or the failed YouTube
  check) and approves it anyway where it can still air.
- **Prayer requests** in a music program are read out word for word between
  songs (up to three, at most 600 characters together), each after a short
  lead-in — with the sender's first name and place, or anonymously if they
  ticked "Stay anonymous" — and the host then invites everyone to pray. The
  host never prays itself.
- **Prayer wall**: typed prayer requests whose senders ticked "show on the
  prayer wall" appear there once approved, with their first name and place
  unless they stayed anonymous (the newest 30). During a prayer hour it shows
  every request of that hour from its reading until the hour ends, ticked or
  not; the tick keeps it there after. /mod → Review → Prayer wall takes one
  down, or puts it back, at once; one taken down is no longer read out.
- **Prayer hour**: a program with the format "Prayer hour" (/mod → Programs)
  is where the listeners pray; the host never does. It runs: welcome → a
  moderator's opening prayer, if one is prepared → the collection (0–3 songs,
  then prayer music until it has lasted its minutes, 10 by default) → the
  requests read out word for word, Open Doors' request of the day first →
  the host opens the prayer time → listeners' prayers until the outro (a
  written one read out word for word after a short lead-in, a spoken one, up
  to a minute, played as it is; requests sent since are read too; a few
  seconds of quiet after each, silence in between, a word of encouragement
  after the "quiet minutes") → outro → songs, if set. It takes prayer
  requests, and in its prayer time prayers ("Pray" on the stage opens the
  recorder, with "Type it instead"). For the collection, upload quiet music
  in /mod → Library → Background music (an MP3 of 20 s – 10 min, at most 8 MB:
  re-encode to about 128 kbps, e.g. `ffmpeg -i in.mp3 -b:a 128k prayer.mp3`)
  and pick it in the program. Anything approved airs about seven minutes
  later: requests sent while the presentation is prepared (its last seven
  minutes) are read in the prayer time. Requests close 15 minutes before the
  outro, prayers 12 minutes before it ("last chance" from 17); the prayer time
  lasts at least 25 minutes. A prayer hour cannot be a channel's fallback
  program (its running order needs an end). While it is on air the wall shows
  its requests as they are read; one on the wall is read without the sender's
  name and marked "On air now". 🙏 on the wall counts once per device (at most
  `PRAY_ALONG_PER_IP_HOUR`, 600, from one address an hour); only the sender
  sees the number, and the outro may say the total. Under the program's
  settings a moderator prepares opening prayers — typed (read word for word
  in the host voice, only in the languages filled in), an MP3 or a recording
  in the browser, up to 3 minutes; each airing takes the oldest one waiting,
  otherwise the hour has no opening prayer.
- **Open Doors**: every prayer hour reads Open Doors Deutschland's daily
  prayer request for persecuted Christians first (their RSS feed,
  `OPENDOORS_FEED_URL`; a program can leave it out, `off` turns it off
  everywhere). German listeners hear it as published, English listeners a
  translation made once a day by the text model; on the wall it says "Open
  Doors". A feed that is down keeps yesterday's request; one older than two
  days is not read.
- **Video programs** — preaching, testimonies, mission, films: add their
  videos in /mod → Library → "Add a song or a video" (kind Preaching,
  Testimony, Mission video or Film; 1 minute – 3 hours, a film up to 4 hours),
  create a program with that format (/mod → Programs) and put it into a plan.
  It runs intro → a video of its kind → N songs (default 2) → the next video
  if one still fits → … → outro; none twice within six hours. While it is on
  air, listeners suggest videos with the fourth tile ("Suggest a video"): the
  sheet offers the kinds the program takes — its own is ticked when you pick
  the format; tick others too (a mission program may take testimonies). The
  lengths a listener may send: `PREACHING_MIN/MAX_SECONDS` (5–90 min),
  `TESTIMONY_VIDEO_MIN/MAX_SECONDS` (2–60), `MISSION_MIN/MAX_SECONDS` (3–90),
  `FILM_MIN/MAX_SECONDS` (5 min – 3 h). Approved, a suggestion airs at the next
  video, announced by name, and joins the library. A waiting suggestion fills
  the queue with its whole length, so for a long program raise "Close when the
  queue holds … minutes"; one that can no longer fit goes to the library at
  once. A film plays only when it fits: make a film program's block longer
  than its films (a 2-hour film needs about 2 h 05), and add films only from
  their studio or an official channel (the check refuses re-uploads). A
  testimony program can also take listeners' own recorded testimonies (tick
  "A testimony"). Only a video program takes suggested videos; elsewhere the
  tile says "Not part of this program".
- **Groups** (/mod → Groups): a preacher, a church, a ministry or an
  artist — a few words in English and German and up to four https links
  (YouTube channel, website, other). Add their YouTube channels by pasting a
  link to one of their videos: the library's videos from those channels join
  the group (older ones once an hourly job has looked their channels up), and
  in Library you can put any item into a group. With "After their videos, the
  host points to more from them", the host's word after one of their videos
  mentions them while the stage shows the words and links. **Someone who asks
  not to be on our platform**: make a group for them with their channels,
  the names their videos are titled with ("Name - Song"; empty: the group's
  name) and a note on when and how they asked, and tick "Not on our
  platform". From then on no listener suggestion of theirs is accepted, no
  moderator can add one, and their library videos never play — what was
  already planned or on air is taken off at once. Untick it and they play
  again.
- **Themes**: Kids Ark (light) and Storm Ark (dark), following the device
  until a listener picks one (welcome dialog, Profile). The design they
  implement is `concept-files/theme-preview.html`; the scenery lives in
  `app/src/assets/theme/`.
- **Retention** (the privacy policy states these — change both together):
  minute files and host audio 48 h, day files 60 days, submissions 90 days
  (`RETAIN_SUBMISSIONS_DAYS`; recordings allowed for replays stay in the
  library; a spoken prayer is never kept for replays, and a recording that
  can no longer air is deleted), the timeline and the host's scripts 30 days
  (they can quote a prayer request or a prayer), who prayed along with a
  request until it is off the wall
  (the number stays with it), prepared opening prayers 90 days after they
  aired, chat voices 7 days, chat reports 30 days, presence 1 day,
  rate-limit entries 2 days, unused anonymous devices 60 days, backups 7 days.
- **Station page** (`/about`, also `/impressum`, `/datenschutz`; the header
  logo opens it): what ARCHE is, the imprint and the privacy policy, in
  `app/src/content/legal.ts` (German binding, English for convenience).

## Voice workers (Qwen3-TTS on our own Macs)

Hosts can speak with a voice made on a Mac of ours (provider "Eigener Rechner").
The Mac pulls voice jobs from the station, so it needs no public address.
Add a worker in /mod › KI-Moderation › Rechner (the key is shown once), then
follow `worker/README.md` on the Mac: `worker/setup.sh`, put the key in
`~/.config/arche-worker/config.toml`, `worker/run.sh check`, `worker/run.sh`.
One worker may serve production and the dev station. With no worker online, a
worker host's moments go to the next host in the lineup.

## Settings

Everything is an `.env` key (defaults in `server/config/defaults.php`, names in
`.env.example`). The ones you are most likely to touch:

| Key | Default | |
|---|---|---|
| `AI_MODE` | `live` | `stub` = no network AI (tests, offline dev) |
| `AI_TEXT_PROVIDER` | `auto` | who writes and moderates: `auto` (Claude if `ANTHROPIC_KEY` is set, else OpenAI), `anthropic`, `openai` |
| `OPENAI_HOST_MODEL`, `OPENAI_MODERATION_MODEL` | `gpt-5.4-mini` | OpenAI model ids (when OpenAI writes and moderates); a new one also needs its price in `Ai\Usage` |
| `HOST_MODEL`, `MODERATION_MODEL` | `claude-opus-5` | Claude model ids (when Claude does) |
| `STATION_LANGS` | `en,de` | languages every host break is voiced in |
| `TICK_BUDGET` | `22` | seconds of network time per tick (from the probe) |
| `PULSE_SECONDS` | `120` | presence pulse interval; `0` turns pulses off under load |
| `MODERATION_HUMAN_REVIEW` | `0` | `1` = uncertain submissions go to /mod → Review |
| `SUBMISSIONS_PER_IP_HOUR`, `IDENTITIES_PER_IP_DAY` | `60`, `300` | per shared address; raise them for an event on one Wi-Fi |
| `REPORTS_PER_IP_HOUR` | `60` | listeners' reports (wall requests, community voices) per shared address |
| `WALL_REPORTS_HIDE` | `3` | different listeners, known for a day, whose reports take a wall request down until a moderator decides (one they kept stays up); `0` = only moderators |
| `REALTIME_DRIVER` | `off` | `off` (no rooms) · `static` (the local stack sets it) · `hcloud` |
| `CDN_BASE_URL` | – | the BunnyCDN zone the app reads `/program` and `/media` from; empty or `off` = the site itself |
