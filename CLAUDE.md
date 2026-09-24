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
Nothing about playback needs a live connection, so the files can sit on a CDN
and scale to millions. Everything else — API, chat, AI — is optional.

```
server/app/Program/Drafter   plan the next 45 min (local, cheap)
        │  host breaks → jobs (script: Claude or OpenAI → TTS en → TTS de)
server/app/Program/Committer fix the timeline up to now + 15 min
server/app/Program/Publisher write minute files up to now + 5 min (+ live, days, channels, evergreen)
        ▼
app/src/lib/engine.ts        item = timeline.at(serverNow()); drive YouTube + our audio; correct drift
```

## Invariants (keep them)

1. **The radio never waits for AI or realtime.** The tick publishes under its
   own lock (`publish`) with no network calls, before any job work (`jobs`
   lock). A host break that is not voiced in time is dropped; a listener's
   announced request is delayed (a filler song goes first), never split.
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
| `scripts/` | deploy (SFTP), assemble, secrets, cron line, host probe, Hetzner setup/snapshot | your Mac |
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

**Submissions** (`Submission\*`, `Moderation\*`). Only to the program on air and
while the minute file says `open`/`closing` (checked again server-side). One job
per submission, one outside call per phase (YouTube → text model; transcribe
→ text model). Recordings arrive as MP3 encoded in the browser (the host has no
ffmpeg) and stay private until approved. Listeners see three generic reasons only.

**Station page and privacy** (`/about`, `app/src/content/legal.ts`). The
imprint and the privacy policy describe what this code does — the data flows
(YouTube only after the join tap, OpenAI for texts/voice/transcripts/checks,
Hetzner for hosting and rooms) and the retention periods, which are
implemented in `Tick::purge` and `defaults.php`. Change code and text together.
Submissions can reveal faith or health (Art. 9 GDPR): the forms say so next
to Send. The page also carries the promised controls (withdraw YouTube
consent, delete this device's data).

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
- The one-minute konsoleH cron is unverified (sibling repos disagree); API
  requests run a tick themselves when the last one is older than 90 s.
- `/_arche/var/maintenance` (a timestamp, written by deploy.sh) pauses ticks; a
  flag older than 15 min is ignored.

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
  drafts with repeats are re-planned (plans without repeats are kept, their
  host breaks may be voiced already). The committed 15 minutes stay.
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
  minute instead of after 45.
- **Closed bottom sheets stay mounted** (translated away, `inert`): render each
  sheet once per page and use `useId()` for form ids — Home shows the submit
  tiles twice (desktop and phone layout).
- **Mobile grids need `grid-cols-1`** (`minmax(0,1fr)`): an implicit `auto`
  column grows to its widest unbreakable child, and a long program subtitle
  once pushed the whole home page — and the YouTube player — past the screen.
- **The production CSP applies to the built app only** (Apache, `*.html`), never
  to `vite dev`. The silent unlock MP3 is a `data:` URI (`media-src … data:`);
  WebSockets are `wss:` only. The e2e suite runs against the built app.

## Testing

"A test is earned by a risk." Server: `npm run test:php` (enoch-style harness,
`server/tests/cases/*`, stub AI, fixed clock) covers plan resolution, the
generator's timing invariants, the PHP→fixture contract, identity, submissions,
moderation fail-closed, realtime tokens/reports/wake/reaper, and the API. App:
`npm test` (Vitest: engine sync/drift/ads/evergreen, timeline, clock, i18n keys,
passphrase, realtime client). Shared: fixture parsing. Lint + typecheck gate all.

End to end: `npm run e2e` starts the e2e stack (`scripts/e2e-stack.sh`: project
`arche-e2e`, web :8090, realtime :8797, data in `.data/e2e`, its own env file
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
pull from air, chat between two listeners. `npm run e2e:reset` starts over.

## Conventions

TypeScript `strict`, `noUncheckedIndexedAccess`, `erasableSyntaxOnly` (Node runs
realtime/ by type stripping — no enums, no constructor parameter properties).
React-hooks lint rules are errors (no setState in effects; adjust during render).
Every UI string goes through i18next with en + de (`tests/unit/i18nKeys.test.ts`).
Comments explain the failure a line prevents. Commits: sentence-case imperative.

## Known gaps / later

BunnyCDN publisher + CDN-log listener counts (the Publisher and pulse interval
are ready for it), ElevenLabs as the default voice, archive *replay* (slot files
are kept 48 h; `days/*.json` keep what played), Capacitor apps, phone background
playback (not possible with YouTube embeds). Pending on the host: the Phase 0.5
probe (cron interval, background run length → `TICK_BUDGET`, WAL, directives)
and the device sync spike on a real iPhone/Android.
