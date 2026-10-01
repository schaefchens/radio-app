# ARCHE — architecture map

This file says **why**. README.md says **how** (run, deploy, operate).
CONCEPT.md and CONCEPT-TECHNICAL.md are the product and technical concept this
code implements.

ARCHE is a Christian community radio: one program that every listener hears at
the same moment, built from embedded YouTube songs, an AI host that speaks in
English and German between them, and what listeners hand in (song requests,
recorded stories, prayers). The whole design follows one rule from the concept:
**the radio must keep playing, even when everything else fails.**

## The one idea

The program is a **deterministic timeline in static files**. A PHP generator
writes one immutable JSON file per minute (`/program/<ch>/slots/YYYYMMDD/HHMM.json`,
UTC); every client derives what is on air from server time and those files.
Nothing about playback needs a live connection, so the files sit on a CDN
(BunnyCDN, pulling from the site, which stays every client's fallback) and
scale to millions. Everything else — API, chat, AI — is optional.

```
server/app/Program/Drafter   plan the next 8 min (local, cheap)
        │  host breaks → jobs (script: Claude or OpenAI → TTS en → TTS de)
server/app/Program/Committer fix the timeline up to now + 5 min
server/app/Program/Publisher write minute files up to now + 2 min, each naming the next 3 (+ live, days, channels, evergreen)
        ▼
app/src/lib/engine.ts        item = timeline.at(serverNow()); drive YouTube + our audio; correct drift
```

The five fixed minutes (`Timing::COMMIT`) are a trade: they are how long the
program outlives a stopped generator (a deploy, a host outage) before clients
play the evergreen loop, and the least time before anything decided now can
air — a request approved now airs about ten minutes later. The three minutes
between draft and commit are what a host break needs to be written and
voiced (a tick or two; the cron runs every minute).

## Invariants (keep them)

1. **The radio never waits for AI or realtime.** The tick publishes under its
   own lock (`publish`) with no network calls, before any job work (`jobs`
   lock). A host break that is not voiced in time is dropped; a listener's
   announced request is delayed (a filler song goes first), never split; a
   prayer hour's moments wait behind silence or prayer music, never a song.
2. **Published files are immutable.** UTC names, temp-file-and-rename writes,
   never a file for a past minute, never an overwrite. A moderator's "pull from
   air" goes through `live.json` (`blocked`), because minute files cannot change.
3. **YouTube's required minimum functionality.** Nothing in front of the player
   ever; ≥ 200×200 when shown; autoplay only when ≥ 50 % visible; the IFrame API
   loads only after consent; `Referrer-Policy` is *not* `no-referrer` (YouTube
   answers error 153 without a Referer and every song would fail).
4. **Moderation fails closed.** No key, no budget, a refusal, repeated errors →
   "not accepted this time". Uncertain → review queue only if enabled, else reject.
5. **No secrets in the client or in URLs** (one exception: the cron key may be a
   query parameter if the panel cannot send headers; the endpoint can then do
   nothing but a rate-limited tick). Roles only for passphrase identities.

## Layout

| Path | What | Where it runs |
|---|---|---|
| `shared/` | TS types + parsers of the program files, the realtime protocol, `fixtures/` (the contract) | imported by app/ and realtime/; fixtures read by PHP tests |
| `server/` | PHP 8.5, namespace `Arche\`: generator, API, jobs, moderation, realtime control | Hetzner Webhosting S (`/_arche/…` + `/api`, `/cron.php`) |
| `server/public/` | the web-root files exactly as deployed, incl. every `.htaccess` and `.user.ini` | web root |
| `app/` | the PWA (React 19, Vite 8, Tailwind v3, zustand, i18next) | web root (`/`, `/assets`) |
| `realtime/` | Node 24 + `ws` chat/presence/reactions node | Docker: local compose, and Hetzner Cloud nodes |
| `infra/realtime/` | what a node runs: compose, Caddyfile, systemd unit, cloud-init template | baked into the node snapshot |
| `scripts/` | deploy (SFTP), assemble, secrets, cron line, host probe, Hetzner setup/snapshot, Bunny zone setup | your Mac |
| `docker/`, `compose.yaml` | local stack: Apache + PHP-FPM like the host, a cron loop, the realtime image; web root in `.data/site`, `/_arche/var` on the `var` volume | your Mac |

The deployed tree (built by `scripts/assemble-site.sh` into `build/site/`):
`/` PWA · `/api/index.php` · `/cron.php` · `/program/*` and `/media/*` written by
PHP at runtime · `/_arche/{app,config,resources,vendor,bin}` + `/_arche/.env` +
`/_arche/var/` (SQLite, uploads awaiting moderation, locks, backups), all behind
`Require all denied`. The SFTP account is jailed to the web root, which is why
private data lives inside it.

## Layers

**Plan vs Generation** (concept decision). `Plan\Catalog` holds channels,
programs, day plans, the week plan and special days (fixed dates or Easter
offsets). `Plan\PlanResolver` turns station-local plans (per-channel timezone,
default Europe/Berlin) into UTC blocks, DST-correct (23/25 h days), gaps filled
by the channel's fallback program. The generator may only do what the program on
air allows: its submission types, themes/moods, host density, silence, jingles.

**Library** (`Library\*`). Moderators add songs by URL; the YouTube Data API
supplies duration/embeddability/region (oEmbed has no duration, and the client
is not trusted). Approved requests graduate automatically (tags only — never the
dedication). Playback errors disable an item only for codes 100/101/150 from ≥ 3
identities *and* when YouTube confirms.

Background music (kind and item `bed`: the prayer hour's prayer music) is an
uploaded MP3 of 20 s – 10 min (`/mod/beds`). A `bed` item plays a piece of it
from `offset`, never longer than the rest of the file, so the app never loops
one; the next piece goes on where the last one ended, and the engine stays on
audio already playing at the right place instead of starting it again (a
continuing piece, or the stage back after a sheet), so the music does not dip.
It fades in and out through `HostAudio`'s GainNode (iOS ignores
`element.volume`). Older clients drop the unknown item type and play the
evergreen loop for that span.

**Host** (`Host\*`, `Ai\*`). Scripts and moderation verdicts come from one
`Ai\TextModel` (`App::text()`): Claude via the Anthropic PHP SDK when
`ANTHROPIC_KEY` is set (`claude-opus-5` by default, `fallbacks: 'default'` on
Opus 5, hard timeouts through a Guzzle transport, no SDK retries), otherwise
OpenAI Chat Completions (`gpt-5-mini` by default) — an OpenAI key alone runs the
whole station. Both answer through a strict JSON schema; every failure is a
result without data, never an exception (except `BudgetExceeded`, which the job
runner retries without counting an attempt). Voiced with OpenAI
`gpt-4o-mini-tts` (ElevenLabs strictly opt-in behind a daily character cap: the
account is a small free one). The German text may
say "heute Abend"; the English one is heard worldwide and stays time-neutral.
All language versions share one slot length: the longest one plus padding.
Cost gates (listeners ≥ `HOST_MIN_LISTENERS`, daily cap, `AI_DAILY_BUDGET_USD`)
run at *script time*, so a listener who tunes in still hears the host soon.
What listeners sent skips the listener gate, and so does a prayer hour's
welcome, opening prayer and invitation: they are written about eight minutes
before the hour, before its listeners tune in, and gated they were lost for
everyone who came on time.

**Submissions** (`Submission\*`, `Moderation\*`). Only to the program on air and
while the minute file says `open`/`closing` (checked again server-side). One job
per submission, one outside call per phase (YouTube → text model; transcribe
→ text model). Recordings arrive as MP3 encoded in the browser (the host has no
ffmpeg) and stay private until approved. Listeners see three generic reasons only;
moderators see the whole verdict (the flags, the model's one-sentence `note`, which
YouTube check failed) in /mod → Review and can overrule a rejection — except a
recording (deleted on rejection, as the privacy policy says) or a video the embed
would not play. The station's length limit can be overruled.

Once approved, a request goes to the end of the plan. What waits for a program
is presented as one block: up to three, the longest-waiting first, fitting
themes next to each other; the host announces each (every request, by name and
place, the dedication when there is one), from the second on first reacts to the
one before, and speaks after the last. Then at least two regular songs before
the next block. Too late for its program — a busy queue, a late human decision —
a song stays in the music selection (`library`, "may play in a later program")
and anything else is `missed`: a recording that will never air is not
published, or removed when the tick sweeps the queue. Intake closes 15 minutes
before a program ends ("last chance" from 25).

Typed prayer requests are not a block: the host prays for up to three in one
`prayer` break, which carries their ids as `prayer_ids` (never `prayers`: the
script phase stores the writer's context, whose `prayers` are the texts, over
the drafted one). Committed, the break marks them with its start; dropped or
discarded, it gives them back to the queue. Each counts 20 s in the queue that
closes intake (`Timing::PRAYER_EACH`).

The **prayer wall** (`Submissions::wall`, `live.json.wall`) shows typed prayer
requests that are approved, scheduled or aired — only with the sender's own
tick (never pre-ticked: Art. 9 needs a clear yes), anonymous (text and time,
no name or place), the newest 30 — while a prayer hour is on air, that hour's
requests (up to 60). In live.json, not the minute files, so a moderator's
takedown (/mod → Review → Prayer wall, `submissions.hidden`) applies at once;
it also drops the repeats a prayer hour planned for it. Community voices are
chat highlights only. 🙏 on a wall request (a `voices` reaction `p…`/`pray` in
the pulse, never a request of its own) is praying along
(`Submissions::prayAlong`): once per device and request — `prayed_along.who`
is an HMAC of both, so it joins neither presence nor the device's other
prayers — and capped per address (`PRAY_ALONG_PER_IP_HOUR`). The rows go as
soon as no wall can show the request; the number (`submissions.prayed_count`)
stays, shown to the sender only (Profile) and as the hour's total in the
outro. The pulse keys voice reactions by voice *and* kind (a ❤️ after a 🙏
used to replace it).

The **prayer hour** (`Program\PrayerHour`; program setting `format: 'prayer'`,
prayer requests only, never a channel's fallback) has its own running order:
welcome → opening prayer → invitation → collection (prayer music in pieces that
go on through the file, or N songs; requests appear on the wall only) → the
prayer time → outro with a blessing at C → songs, if the program wants them.
C = the run's end − `after_songs` × 4 min − 45 s, from the plan alone (an
average song length would move the intake times already published). The run
is `PlanResolver::runAt` (one run across midnight, where `blockAt` starts a new
block); where the hour stands is read from its own items after the last item
of another program (by seq), so an outage or a plan change mid-hour does not
start it over, and a moment its gate refused (`failed`) or its time overtook
(`skipped:late`) counts as tried. In the prayer time the plan reaches only
`PRAYER_LEAD` (7 min) ahead — the step returns null and `Drafter::draft()`
stops — so each moment takes what was approved since the last (three at most,
`read` if sent during the collection, else `new`), with a pause of silence
after it; quiet for `quiet_min` → one wall request again (`again`), an empty
wall → `general`; otherwise 60 s pieces of "Silent prayer". The welcome,
opening, invitation, outro and every moment with requests are units: late,
they wait behind the hour's own filler (`PrayerHour::filler`: prayer music
before the opening, silence later, in the waiting unit's program — never the
previous program's song). A request on the wall reaches the model without
name and place (it is prayed for anonymously); the moment's `prayers` lists
the wall ids so the app can show "Praying now". Intake and `canStillAir`
count to C. A moderator can prepare opening prayers (`Program\OpeningPrayers`,
/mod → Programs; not a Catalog write, so no drafts are thrown away): each
airing takes the oldest waiting — a recording airs as a `contrib` item, a text
as host `opening` with `context.fixed`, which the script phase voices word for
word without the model, in the languages filled in — and is marked aired at
commit; the welcome names who prays (`opening_by`).

**Themes** (`app/src/lib/theme.ts`, `app/src/styles/`). The design is
`concept-files/theme-preview.html`: Kids Ark (light) and Storm Ark (dark),
desktop and phone. A theme is one registry entry (name, scenery, bar color)
plus one token block in `styles/tokens.css`, picked by `<html data-theme>`;
with no choice (`settings.theme = null`) the device's scheme decides — the
tokens repeat the dark block under `prefers-color-scheme` because the CSP
allows no inline script to set the attribute before the first paint.
See-through surfaces take their alpha from `--*-a` variables, which
`prefers-reduced-transparency` sets to 1. `shell.css`/`home.css`/`welcome.css`
keep the preview's class names (its container queries are media queries
here: phone ≤ 600, desktop ≥ 900) and load after `index.css`, after
Tailwind's reset. The stage is `data-theme="dark"` in both themes. The
scenery is not precached (workbox `globIgnores`, cached on first use). A
first visit gets the welcome dialog (language, theme); reactions stay
pressed per device (`arche.reactions`).

**Station page and privacy** (`/about`, `app/src/content/legal.ts`). The
imprint and the privacy policy describe what this code does — the data flows
(YouTube only after the join tap, OpenAI for texts/voice/transcripts/checks,
Hetzner for hosting and rooms, BunnyCDN for delivery with IP-less logs kept
three days) and the retention periods, which are
implemented in `Tick::purge` and `defaults.php`. Change code and text together.
Submissions can reveal faith or health (Art. 9 GDPR): the forms say so next
to Send. The page also carries the promised controls (withdraw YouTube
consent, delete this device's data).

**CDN** (`Cdn\Bunny`, `app/src/lib/cdn.ts`). A BunnyCDN pull zone in front of
`/program` and `/media`, never pushed to: the edge honours the origin's
Cache-Control, so immutability and the no-store 404 carry over, and the
publish phase stays offline. The app learns the zone from `/api/session`
(remembered locally) and falls back to the site on any edge failure except a
404, which is the origin's own answer (asking again would double the site's
load exactly when the generator is late). The jobs phase purges deleted media
at the edge (a host clip can name a listener) and counts each minute file's
requests in the zone's log (IPs dropped) as that minute's listeners. The page
CSP names the zone via `assemble-site.sh --cdn`.

**Identity** (`Identity\*`). Anonymous-first: a device id + secret (HMAC'd with a
pepper), rows created lazily. The optional 12-word BIP39 passphrase never
leaves the device; its seed yields credId + credSecret (Argon2id at claim/login
only). Login on another device makes that device's row an alias (`canonical_id`).

**Realtime** (`Realtime\*`, `realtime/`). Optional by design. PHP signs Ed25519
join tokens (the node has only the public key); nodes report every 20 s with an
HMAC-signed, timestamped body (trend deltas, presence, highlight candidates,
abuse reports — additive, idempotent on re-send). Wake state lives in SQLite so
polls never call Hetzner; a node is "ready" at its first report. Scale-to-zero:
idle/silent/old nodes are deleted by the tick; certificates survive on a
per-slot Volume (Let's Encrypt allows 5 duplicate certs a week).

## Host constraints (Hetzner Webhosting S)

- ProFTPD SFTP, **no shell, no PHP CLI**, no composer: `vendor/` is uploaded.
- **Access checks run before mod_rewrite.** A blanket `Require all denied` in
  `/api/.htaccess` refused `/api/time` before it could be routed to index.php.
  Deny by `FilesMatch` instead (see `server/public/api/.htaccess`).
- `php_value` in `.htaccess` is a 500 under PHP-FPM → limits in `.user.ini`.
- `SetEnvIf Authorization …` or Bearer auth never reaches PHP.
- `.js` is served as `text/javascript`; it must be in `AddOutputFilterByType`.
- An `AllowOverride` violation is a hard 500 for the whole site, and
  `<IfModule>` does not protect against it. `scripts/probe` tests every directive.
- The konsoleH cron runs every minute (seen in production 2026-09-24: a call
  at :01, 60 s apart); API requests still run a tick themselves when the last
  one is older than 90 s.
- `/_arche/var/maintenance` (a timestamp, written by deploy.sh) pauses ticks; a
  flag older than 15 min is ignored. A deploy that uploads for longer than the
  five fixed minutes plays the fallback loop until it is done.

## Gotchas that already bit

- **SQLite + PDO**: bind integers as integers (`Store::query` does). PDO binds
  text by default, and `start_ms + dur_ms > ?` against a text value is always
  false in SQLite (every integer sorts before every string) — the retention
  purge once deleted a whole fresh timeline because of it.
- **zustand selectors must return stable references** (`?? []` in a selector
  loops React; use a module constant).
- **One YouTube player for the page's lifetime**, in `PlayerLayer`, floating over
  the current stage slot. Re-parenting an iframe reloads it; each page having its
  own player stopped the music on every navigation.
- `lib/radio.ts` self-accepts HMR and reloads the page: re-running it would
  start a second engine. (Vite 8's `import.meta.hot.decline()` is a no-op.)
- PHP 8.5: `curl_close()` is deprecated — don't call it.
- An `<audio>` routed through Web Audio plays a cross-origin file as silence
  unless it was loaded with CORS: `HostAudio` sets `crossOrigin` for the CDN.
- A CDN copy carries the `Date` of its first fetch (and cross-origin the
  header is hidden anyway): only the site's own responses feed the clock.
- `/program` and `/media` already send `Access-Control-Allow-Origin: *` from
  their `.htaccess`; a second copy of the header makes browsers refuse it.
- **SQLite WAL needs a real local filesystem.** Its index (`-shm`) is shared
  memory mapped by every PHP worker; on a Docker Desktop bind mount the workers
  died with SIGBUS under load (ticks cut mid-phase, jobs stuck until their
  lease ran out). Locally `/_arche/var` is therefore a Docker volume; on the
  host the probe checks WAL (`SQLITE_WAL=0` falls back to a rollback journal).
- **PHP's umask on the host is 0027, and Apache is another user**: every
  directory under `/program` and `/media` must be created (and repaired) with
  an explicit 0755 (`Files::ensureDir`) — a 0750 folder made every minute file
  a 403 on the first production deploy. The Docker bind mount ignores
  permissions, so only the host shows it.
- **A plan made from a tiny library repeats songs**; when the library changes,
  drafts that repeat a song from the last hour are re-planned (plans without
  repeats are kept, their host breaks may be voiced already). The committed
  minutes stay.
- **Throttle on the last tick, not the last request** (`CronEndpoint::due`):
  counting every request let calls a few seconds apart hold the tick off
  indefinitely.
- **`AI_MODE=stub` stubs AI only.** The YouTube Data API is used whenever
  `YOUTUBE_API_KEY` is set (the e2e stack points `YOUTUBE_API_BASE` at a fake);
  a stubbed check once waved a 15-minute song request through.
- **A new station**: drafted with an empty library, the plan is placeholders
  (`stage` items with `payload.waiting`). The first songs discard those drafts
  and block the committed ones in live.json, and the fallback loop is rebuilt
  in the same tick (library fingerprint), so listeners hear music within a
  minute instead of after the placeholders.
- **Closed bottom sheets stay mounted** (translated away, `inert`): render each
  sheet once per page and use `useId()` for form ids — Home shows the submit
  tiles twice (desktop and phone layout).
- **Mobile grids need `grid-cols-1`** (`minmax(0,1fr)`): an implicit `auto`
  column grows to its widest unbreakable child, and a long program subtitle
  once pushed the whole home page — and the YouTube player — past the screen.
- **The production CSP applies to the built app only** (Apache, `*.html`), never
  to `vite dev`. The silent unlock MP3 is a `data:` URI (`media-src … data:`);
  WebSockets are `wss:` only. The e2e suite runs against the built app.

- **Nothing opens over the stage.** An emoji picker or channel list above the
  player's row would sit in front of the YouTube player (z 35, fixed): pickers
  open downward, the channel list is a sheet. A modal (sheet, the welcome
  `<dialog>`) must `pushOverlay` so the video pauses under it.
- **No `container-type` above the fixed layers.** Its layout containment makes
  the element the containing block for `position: fixed` — the phone dock and
  scenery would stick to the page instead of the screen (the preview uses
  container queries only because it sits in a demo frame).
- **The phone scenery at the bottom is Home's only**: behind the other pages'
  text, which has no cards, it took the words away.

## Testing

"A test is earned by a risk." Server: `npm run test:php` (enoch-style harness,
`server/tests/cases/*`, stub AI, fixed clock) covers plan resolution, the
generator's timing invariants, the PHP→fixture contract, identity, submissions
(request blocks, late approvals, the queue sweep, intake times, the prayer wall,
typed prayers aired or given back, praying along), the prayer hour walked hour
by hour (`prayerhour.php`: the running order, the rolling reading, repeats,
the empty hour, intake to C, midnight, outages, last-minute and repeated plan
changes, nobody listening, bursts, a short hour, the fallback rule, opening
prayers), prayer music, moderation fail-closed, realtime tokens/reports/wake/reaper, the CDN (log count,
purge queue), and the API. App: `npm test` (Vitest: engine sync/drift/ads/evergreen,
prayer music's fades and continuing pieces, timeline, clock, i18n keys, passphrase,
realtime client, CDN fallback, theme, the phone carousel's fit, the prayer wall's
day, "Praying now" and the silent-prayer pick, the pulse's voice reactions; jsdom:
the stage's prayer views, the prayer sheet's wall box). Shared:
fixture parsing. Lint + typecheck gate all.

End to end: `npm run e2e` starts the e2e stack (`scripts/e2e-stack.sh`: project
`arche-e2e`, web :8090, a stand-in CDN :8091, realtime :8797, data in `.data/e2e`, its own env file
with stub AI and no real key; its database on the `arche-e2e_var` volume) and runs Playwright (`app/tests/e2e`) against the
production build behind Apache with the real `.htaccess` headers. YouTube is
faked on both sides: the Data API by `fake-youtube.mjs` (a container;
`YOUTUBE_API_BASE` points PHP at it), the IFrame API by `support/youtube-shim.js`
(served by `page.route`; a "video" is a clock the test can read). The global
setup makes the admin and the library through the real API, like production.
Covered: live position on join, two listeners in sync, evergreen fallback, no
Google request before consent, RMF (nothing over the player, ≥ 200×200, paused
under a sheet), no sideways scroll at 360/390 px on every page, passphrase on a
second device, song/prayer/recording through moderation, /mod gate, library,
pull from air, a rejection explained and overruled in /mod, the welcome dialog
(language and theme, once; `fakeYouTube()` pre-dismisses it for every other
test), the theme following the device until Profile picks one, the pinned
phone player and the tiles unfolding, a prayer on the prayer wall (anonymous),
no sideways scroll and the stage uncovered in both themes, chat between two
listeners, the program read cross-origin from
the stand-in CDN (CSP included) and from the site when the CDN is down, a
prayer hour on a channel of its own (prayer music on the stage, a request from
the stage onto the wall without its sender, praying along, the sender's count,
no sideways scroll). `npm run e2e:reset` starts over.

The dev and e2e stacks mount `server/app` live and their cron loops tick every
minute: a half-written change runs there at once (and migrates their
databases). A database migrated by an unmerged branch keeps that branch's
numbering — the next versions on main are skipped there, so new tables and
columns get names the branch did not use.

## Conventions

TypeScript `strict`, `noUncheckedIndexedAccess`, `erasableSyntaxOnly` (Node runs
realtime/ by type stripping — no enums, no constructor parameter properties).
React-hooks lint rules are errors (no setState in effects; adjust during render).
Every UI string goes through i18next with en + de (`tests/unit/i18nKeys.test.ts`).
Comments explain the failure a line prevents. Commits: sentence-case imperative.

## Known gaps / later

A custom hostname for the CDN zone, ElevenLabs as the default voice, archive *replay* (slot files
are kept 48 h; `days/*.json` keep what played), Capacitor apps, phone background
playback (not possible with YouTube embeds). Pending on the host: the Phase 0.5
probe (background run length → `TICK_BUDGET`, WAL, directives) and the device
sync spike on a real iPhone/Android, and there the prayer music (it plays after
the join tap, fades, goes on across pieces, comes back after the background).
More program formats with a running order beyond the prayer hour.
