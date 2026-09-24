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
moderates instead). Costs are capped by `AI_DAILY_BUDGET_USD`, and nothing is
generated while nobody listens. One real host break without touching the
program: see `server/bin/try-host.php`.

First admin, exactly as in production: open http://localhost:5180/profile,
create a passphrase, then open /setup and enter `ADMIN_SETUP_KEY` from `.env`.
Then build programs, plans and the song library in /mod.

## Test

```bash
npm run verify          # typecheck + lint + all JS tests + PHP tests
npm test                # shared, app, realtime (Vitest)
npm run test:php        # server tests in the PHP 8.5 container
npm run e2e             # Playwright against a separate e2e stack (:8090), see below
```

`npm run e2e` builds the PWA and starts its own Docker project (`arche-e2e`,
web :8090, realtime :8797) on `.data/e2e`, with stub AI, a fake YouTube and no
real keys — the dev stack and its data are not touched. The first run on a
fresh e2e station waits a minute or two until the program is on air.
`npm run e2e:down` stops it, `npm run e2e:reset` deletes its data. Needs the
Playwright browser once: `npx playwright install chromium`.

## Deploy (Hetzner Webhosting S)

1. **Probe the host first** (once): `bash scripts/probe/probe.sh --upload`, add the
   printed cron line in konsoleH, wait ~24 h, `--read`, then `--remove`. It tells
   the real cron interval, how long PHP may run after a request (→ `TICK_BUDGET`),
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
`realtime/` or `infra/realtime/`.

Live since 2026-09-24: slot `rt1` in fsn1, nodes on **cpx12** (x86) with
**cpx22** as the fallback when Hetzner refuses the first type — Arm (`cax*`)
was sold out in every location that day. The snapshot must match the node
architecture: `npm run realtime:snapshot -- --type cpx12` (the default); it
says which types a location can create when the one asked for cannot be. A
node costs about €0.02 per hour while a room is open, is deleted after 10
minutes without anyone and after 12 hours at the latest. The very first start
(or one after ~90 days without any) waits for its Let's Encrypt certificate,
which then stays on the slot's volume; the app's reconnect covers the wait.

## Operate

- **/mod → Status**: last tick, how far ahead each channel is committed, jobs,
  host-break outcomes, AI spend vs budget, realtime nodes, audit log.
- **Costs**: `AI_DAILY_BUDGET_USD` (all AI), `HOST_MAX_BREAKS_PER_DAY`,
  `HOST_MIN_LISTENERS`, `MODERATION_MAX_PER_DAY`. ElevenLabs is used only with
  `TTS_PROVIDER=elevenlabs` and `ELEVENLABS_MAX_CHARS_PER_DAY` > 0.
- **Backups**: a daily `VACUUM INTO` copy in `/_arche/var/backups` (seven kept).
- **Pull from air**: /mod → Library → pull; clients skip it within a minute.
- **Retention** (the privacy policy states these — change both together):
  minute files and host audio 48 h, day files 60 days, submissions 90 days
  (`RETAIN_SUBMISSIONS_DAYS`; recordings allowed for replays stay in the
  library), chat voices 7 days, chat reports 30 days, presence 1 day,
  rate-limit entries 2 days, unused anonymous devices 60 days, backups 7 days.
- **Station page** (`/about`, also `/impressum`, `/datenschutz`; the header
  logo opens it): what ARCHE is, the imprint and the privacy policy, in
  `app/src/content/legal.ts` (German binding, English for convenience).

## Settings

Everything is an `.env` key (defaults in `server/config/defaults.php`, names in
`.env.example`). The ones you are most likely to touch:

| Key | Default | |
|---|---|---|
| `AI_MODE` | `live` | `stub` = no network AI (tests, offline dev) |
| `AI_TEXT_PROVIDER` | `auto` | who writes and moderates: `auto` (Claude if `ANTHROPIC_KEY` is set, else OpenAI), `anthropic`, `openai` |
| `OPENAI_HOST_MODEL`, `OPENAI_MODERATION_MODEL` | `gpt-5-mini` | OpenAI model ids (when OpenAI writes and moderates) |
| `HOST_MODEL`, `MODERATION_MODEL` | `claude-opus-5` | Claude model ids (when Claude does) |
| `TTS_PROVIDER` | `openai` | `elevenlabs` only together with `ELEVENLABS_MAX_CHARS_PER_DAY` > 0 |
| `STATION_LANGS` | `en,de` | languages every host break is voiced in |
| `TICK_BUDGET` | `22` | seconds of network time per tick (from the probe) |
| `PULSE_SECONDS` | `120` | presence pulse interval; `0` turns pulses off under load |
| `MODERATION_HUMAN_REVIEW` | `0` | `1` = uncertain submissions go to /mod → Review |
| `SUBMISSIONS_PER_IP_HOUR`, `IDENTITIES_PER_IP_DAY` | `60`, `300` | per shared address; raise them for an event on one Wi-Fi |
| `REALTIME_DRIVER` | `off` | `off` (no rooms) · `static` (the local stack sets it) · `hcloud` |
