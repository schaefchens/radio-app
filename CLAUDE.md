# ARCHE — architecture map

This file says **why**. README.md says **how** (run, deploy, operate).
CONCEPT.md and CONCEPT-TECHNICAL.md are the product and technical concept this
code implements.

ARCHE is a Christian community radio: one program that every listener hears at
the same moment, built from embedded YouTube songs, an AI host that speaks in
English and German between them, and what listeners hand in (song requests,
suggested videos — preachings, testimonies, mission videos, films —, recorded
stories, prayers). The whole design follows one rule from the concept:
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
   prayer hour's moments and readings wait behind its prayer music or
   silence, never a song.
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
6. **The host never prays.** Listeners pray; the host welcomes, presents and
   invites them to. No prayer, blessing, "Amen" or "let us pray" in its words:
   the prompt says so, `HostWriter::prays()` swaps a model answer that prays
   for the template, and no template prays (`host.php` checks every one).
   People's own words are read out as written, without the model.

## Layout

| Path | What | Where it runs |
|---|---|---|
| `shared/` | TS types + parsers of the program files, the realtime protocol, `fixtures/` (the contract) | imported by app/ and realtime/; fixtures read by PHP tests |
| `server/` | PHP 8.5, namespace `Arche\`: generator, API, jobs, moderation, realtime control | Hetzner Webhosting S (`/_arche/…` + `/api`, `/cron.php`) |
| `server/public/` | the web-root files exactly as deployed, incl. every `.htaccess` and `.user.ini` | web root |
| `app/` | the PWA (React 19, Vite 8, Tailwind v3, zustand, i18next) | web root (`/`, `/assets`) |
| `app/capacitor.config.ts`, `app/ios/`, `app/android/`, `app/native/` | the store apps: Capacitor 8 shells around the live site, their offline page (`native/www`) and icon sources (`native/assets`) | App Store, Google Play |
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

**Library** (`Library\*`). Moderators add songs and videos (kinds
`preaching`, `testimony`, `mission`, `film`) by URL; the YouTube Data API
supplies duration/embeddability/region (oEmbed has no duration, and the client
is not trusted). Approved requests graduate automatically (tags only — never the
dedication). Playback errors disable an item only for codes 100/101/150 from ≥ 3
identities *and* when YouTube confirms.

**Groups** (`Library\Groups`, /mod › Groups): a preacher, a church, a
ministry or an artist, with a few words (en/de, ≤ 200 characters) and up to
four https links. A video belongs to one by its YouTube channel
(`library_items.yt_channel`, from the Data API; older items learn it from the
hourly `channels` job, 50 per call, in the runner's budget) or because a
moderator put it there (`group_id`); one channel belongs to one group. With
`notice`, the host's moment right after one of its items (`break`, `outro`
or a video's introduction — never in a prayer hour) gets `previous_group`:
one sentence pointing to more from them, nothing beyond `about`, never a web
address. The committed host item carries `notice` for the stage
(`HostBreaks::payload`, only while the item before is still theirs): the card
shows while the host speaks — no host, no notice; older apps ignore the field,
and the parser keeps https links only. `blocked` is for those who asked not to
be on our platform. Blocking also matches the artist a title names, exactly as
the group lists its names, to catch re-uploads of their songs on other
channels: a suggestion is "not accepted" (`group_blocked`, which no moderator
can overrule while it stands), a moderator cannot add one, the selection, the
fallback loop and a waiting request skip theirs, and blocking takes what is
planned or on air like "Pull from air" — without switching the items off, so
unblocking brings them back.

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
OpenAI Chat Completions (`gpt-5.4-mini` by default) — an OpenAI key alone runs the
whole station. Both answer through a strict JSON schema; every failure is a
result without data, never an exception (except `BudgetExceeded`, which the job
runner retries without counting an attempt). Voiced by the moment's host
(below), with OpenAI or ElevenLabs. The German text may
say "heute Abend"; the English one is heard worldwide and stays time-neutral.
All language versions share one slot length: the longest one plus padding.
Cost gates (listeners ≥ `HOST_MIN_LISTENERS`, daily cap, `AI_DAILY_BUDGET_USD`)
run at *script time*, so a listener who tunes in still hears the host soon.
What listeners sent skips the listener gate, and so does a prayer hour's
welcome, presentation and announcement: the welcome is written about eight
minutes before the hour, before its listeners tune in, and gated it was lost
for everyone who came on time; the hour's order must not depend on who
listens. People's words read out (`reading`, `intercession`, a prepared
opening prayer) are neither stopped nor counted by the daily cap. The job
voices only the languages that have text (`HostBreaks::nextLang`).

**Hosts** (`Host\Hosts`, /mod › Hosts, admins; station decision 2026-10-05).
Each host is a persona — picture, name, color, a few words in en/de that
listeners read *and* the writer is given, private style notes for the writer
— with a voice of its own: OpenAI or ElevenLabs, model, voice (one per
language if wanted), OpenAI's voice direction (`instructions`), settings, and
its own key: write-only, sealed with a key derived from the identity pepper
(`Support\Sealed`), never in a response, the audit or an error (`VoiceError`
redacts); an OpenAI host without one speaks with `OPENAI_KEY`. A daily
character cap counts `ai_usage` kind `tts:host:<id>`; an ElevenLabs host
without a cap never speaks (fail closed: the station's account is a small
free one). A program's **lineup** (`host_lineups`: on air, then fallbacks in
order; else its channel's, else the main channel's) is written with the
program's PATCH under `hosts` — never inside `settings`, which `cleanSettings`
resets from an older /mod tab. **One host per show** (a run of the program,
`PlanResolver::runAt` of the item's `block_start`; a run longer than a day is
one show per local day), picked at random among the on-air hosts who can
speak, not the previous show's when another can, kept in kv
`host_show:<cid>:<pid>`. The host is chosen at script time (`Hosts::forBreak`):
the show's, else one of the same name (the same persona with another voice),
else the fallbacks, else the other on-air hosts; one host voices every
language of a moment (`hasRoom` checks the cap for all of them). A voice that
fails: account or setup errors (400–404, 422, quotas) rest the host until the
next UTC day or until it is saved, temporary ones count (three in a row: ten
minutes); the moment goes to the next host who can (`context.tried`, at most
four) — voiced again, or written again first when the new one has another
name and the model wrote the words; nobody left: a temporary error is
thrown for the job's retry, anything else fails `no_voice`. A timeout cut
short by the tick is `BudgetExceeded`, never the host's. The generator plans
host moments only while someone in the program's lineup can speak
(`HostBreaks::available($channel, $program)`): a request nobody could read
was taken, failed and given back over and over. The committed item carries
`host` (`Hosts::info`: name, avatar, color, about, voice) — the stage, host
card and song bar show it, older apps keep `channels.json` `host` (the
channel's first) —, day files carry each program's on-air `hosts`, and
program refs `voicedBy`: with `elevenlabs` in it the forms' privacy note says
an ElevenLabs voice may read out what is sent (privacy policy, `legal.ts`),
and the song bar credits "voice: elevenlabs.io" (the free plan's attribution).
Stub mode calls no voice provider for any host; "Try voice" in /mod speaks
the editor's unsaved changes and counts against the cap. The channels'
`host_*` columns are unused since migration 13.

**Lines** (`Host\Lines`, /mod › Lines, moderators; station decision
2026-10-06, after OpenAI announced its speech models' end for 2027-01-06).
Per host, a library of recorded lines: written once (the text model with
`HostWriter::system()`, kind `lines_write`, or a moderator by hand), recorded
once in the host's voice (OpenAI or ElevenLabs, one language per job phase,
usage `tts:lines:<id>` — never the daily cap of moments on air), then picked
by the AI (`lines_pick`, schema `{id: enum}`; without the model — no key, the
day's budget spent, a refusal — the one aired longest ago) for the moments a
program takes from the library: `program_lines` (mode `fresh`, the default,
or `library` with its kinds: intro, outro, encourage, present, prayertime,
prayer, break), outside program settings for the reason the lineup is. A
picked line is `source='library'`, ready at once without a voice call, with no
`next_uid` (it names no song); it airs even when nobody in the lineup can
speak (`HostBreaks::available(…, $kind)`, Drafter/PrayerHour `$hostFor`) —
OpenAI-recorded lines keep playing after OpenAI's shutdown — and neither the
daily cap nor the budget stops it. Readings, requests, a moderator's opening
prayer, video intros and a welcome that must name who prays stay fresh
(`Lines::fits`). Lines never pray (`HostWriter::prays()` on every line,
`line_prays` for a moderator's). Which line may air: active, recorded with the
host's current voice (`Lines::signature`: provider — `stub` in stub mode —,
model, voices, direction, settings; `old_voice` keeps earlier ones), the
program's own first (intro, outro, present, prayertime name their program),
the time tag fitting the channel's local time (a German line may say "heute
Abend"), not aired within `rest_hours` while others have rested; counted at
commit. Each host's options (`host_line_options`, admins): refill below a
target per kind (job `lines` every 10 min, in the runner's budget), new lines
live or as drafts for approval, rest hours, a monthly recording allowance
(OpenAI 100,000 characters; ElevenLabs 0 — records nothing until an admin
says, like the daily cap). Files live in `/media/lines/<id>-<hash>.<lang>.mp3`:
never in `media/host` (pruned after 48 h), and every break-clip deletion
(`HostBreaks::deleteClips`) touches `/media/host/` only, since many moments
share one recording; removing a line, its host or its program deletes its
files. The `part` column and the mode `composed` wait for Stage 2 (breaks from
recorded pieces plus a short fresh part, joined in PHP) — plan in
`~/.claude/plans/swirling-skipping-reef.md`.

**Submissions** (`Submission\*`, `Moderation\*`). Only to the program on air and
while the minute file says `open`/`closing` (checked again server-side). Every
form asks "Stay anonymous" (`NameOrAnonymous`, unticked at first: named is the
station's default): unticked, a first name is needed, and it is said on air
with the place (and shown on a prayer wall); ticked, the form sends neither,
and the host, the stage and the wall name nobody. One job
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

Typed prayer requests are not a block: up to three (at most `READ_CHARS`
together) are read out word for word, each by a host `reading`
(`HostWriter::reading()`: a short lead-in that changes from one reading to the
next, then the text in its own language only, no model; with the sender's
first name and place unless they stayed anonymous), and then the host's
`prayer` break invites everyone to pray for them. A reading carries its
request's id as `prayer_ids` (never `prayers`: the script phase stores the
writer's context, whose `prayers` are the texts, over the drafted one).
Committed, it marks the request with its start; dropped or discarded, it gives
it back to the queue. A request taken off the wall is not read out any more
(`Timeline::dropRepeatsOf` gives it back, `takePrayers` skips it). Each counts
20 s in the queue that closes intake (`Timing::PRAYER_EACH`).

The **prayer wall** (`Submissions::wall`, `live.json.wall`) shows typed prayer
requests that are approved, scheduled or aired — only with the sender's own
tick (never pre-ticked: Art. 9 needs a clear yes), with text, time and the
first name and place the sender gave — none for one who ticked "Stay
anonymous" (then the form sends neither; named is the default, and among many
requests a name helps listeners find their way) — the newest 30; while a
prayer hour is on air, every one of that hour's requests as it is read out,
ticked or not (the form says so; there the tick keeps it on the wall after the
hour), up to 60: each carries `from` (its reading's start), and the engine
shows it from then on, for everyone at once; during the collection
`live.json.collected` counts what came in
(`Submissions::onWall($s, $prayerHour)`). In live.json, not the minute files,
so a moderator's takedown (/mod → Review → Prayer wall, `submissions.hidden`)
applies at once; a request taken down is no longer read out either
(`Timeline::dropRepeatsOf` drops its planned reading and gives it back).
Praying along and reports follow what a wall shows
(`Submissions::shownOnWall`). Community voices are chat highlights
only. 🙏 on a wall request (a `voices` reaction `p…`/`pray` in the pulse, never
a request of its own) is praying along (`Submissions::prayAlong`): once per
device and request — `prayed_along.who` is an HMAC of both, so it joins
neither presence nor the device's other prayers — and capped per address
(`PRAY_ALONG_PER_IP_HOUR`). The rows go as soon as no wall can show the
request; the number (`submissions.prayed_count`) stays, shown to the sender
only (Profile) and as the hour's total in the outro. The pulse keys voice
reactions by voice *and* kind (a ❤️ after a 🙏 used to replace it).

The **prayer hour** (`Program\PrayerHour`; program setting `format: 'prayer'`,
types `prayer` and `intercession`, never a channel's fallback) is where the
listeners pray — the host never does. Its running order: welcome → a
moderator's opening prayer, if one is prepared (none: no opening prayer) →
the collection (`collect.songs` songs, 0–3, then prayer music in pieces that
go on through the file, until `collect.minutes` have passed; requests come in,
the stage shows how many) → the presentation (`present`, then one `reading`
per request: Open Doors' request of the day first, then the requests sent
until the presentation was planned — a fixed cutoff, `until` in its context —
each read word for word and on the wall from that moment) → `prayertime`, the
host's announcement → the prayer time → the outro at C → songs, if the program
wants them. In the prayer time each step takes the oldest of what listeners
sent: a request (`reading`), a written prayer (`intercession`, read word for
word after a lead-in), a spoken prayer or recorded request (a `contrib`, no
host intro); every one is followed by `PRAYER_GAP` of quiet inside its item.
Nothing waiting → silence ("Prayer time"); quiet for `quiet_min` → `encourage`
(the host invites everyone to pray for what is on the wall, never one picked
request). Prayers are taken only from the committed announcement
(`prayerTimeFrom`: drafts never count, so minute files and the server agree)
until `PRAYER_CLOSED` before C (last chance from `PRAYER_CLOSING`); the
presentation ends by `C − PRAYER_CLOSING − 2 min` at the latest, and the prayer
time lasts at least `MIN_PRAYER` (25 min). C = the run's end − `after_songs` ×
4 min − 45 s, from the plan alone (an average song length would move the
intake times already published). The run is `PlanResolver::runAt` (one run
across midnight, where `blockAt` starts a new block); where the hour stands is
read from its own items after the last item of another program (by seq), so an
outage or a plan change mid-hour does not start it over, and a moment its gate
refused (`failed`) or its time overtook (`skipped:late`) counts as tried — a
request is tried twice at most (`state()['tries']`). From the presentation on
the plan reaches only `PRAYER_LEAD` (7 min) ahead — the step returns null and
`Drafter::draft()` stops — so each reading takes what was approved by then.
The welcome, opening, presentation, announcement, outro and every reading are
units: late, they wait behind the hour's own filler (`PrayerHour::filler`:
prayer music before the prayer time, silence in it, 20 s pieces, in the
waiting unit's program — never the previous program's song); a reading waits
at most `READING_WAIT` from when it was first due, then gives its request back.
Requests and prayers are read, and shown on a wall, with the first name and
place their senders gave; whoever ticks "Stay anonymous" gives neither (every
submission form has it, `NameOrAnonymous`; station decision 2026-10-04,
replacing a rule that read and showed wall requests without names). A moderator can prepare
opening prayers (`Program\OpeningPrayers`,
/mod → Programs; not a Catalog write, so no drafts are thrown away): each
airing takes the oldest waiting — a recording airs as a `contrib` item, a text
as host `opening` with `context.fixed`, which the script phase voices word for
word without the model, in the languages filled in — and is marked aired at
commit; the welcome names who prays (`opening_by`).

People's own words are read out by `HostWriter::reading()`, never by the
model: a short lead-in from `Templates::leadIn()` (pools per case and
language; `n`, the reading's place among the channel's readings, makes
neighbours differ) and the text, in the text's own language only (the
check's `languages`, else the sender's app language) — never translated.
**Open Doors** (`Program\OpenDoors`): a job (`opendoors`: fetch → translate,
hourly, in the runner's budget, never in the publish phase) keeps Open Doors
Deutschland's daily request for persecuted Christians (`OPENDOORS_FEED_URL`,
RSS parsed without network or entities; `off` turns it off) and its English
translation in the kv store. A prayer hour adds it once per run as a request
of the station's own identity (`Identities::station()`, listed nowhere in
/mod), dated to the run's start so it is read first: German as published,
English translated, on the wall with `source` and `texts`, never counted as a
listener's (program setting `prayer.opendoors`).

A **video program** (program setting `format`: `preaching`, `testimony`,
`mission` or `film` — `Catalog::VIDEO_FORMATS`, restated in
`shared/src/constants.ts`; `Drafter::addVideo`) airs YouTube videos of the
library kind its format names — sermons, testimonies (Glaubenszeugnisse),
mission videos (field reports, street preaching, documentaries), Christian
films of two hours and more — with `preaching.songs_between` songs between
them (the settings group keeps the name of the first video format: renamed,
a moderator's value would be lost to a /mod tab opened before a deploy, or to
a request served while it uploads). The host introduces each — the
program's intro or a break right before it does (both are pinned to what
follows), else a moment of its own named after the kind — a host `break`
follows it, and the next starts only if it fits into what is left of the
program's whole run (`PlanResolver::runAt`, `SOFT_OVERRUN` included:
`blockAt` starts a new block at midnight, and "the same program goes on"
once let a two-hour film taken at 22:30 run past a program ending at 00:30);
otherwise songs fill the rest. Listeners suggest videos with one sheet, the
4th tile ("Suggest a video", where the chat tile was — the chat is in the
menu): a picker of the four kinds like the recording sheet's, enabled as the
minute file lists them open, opening on the program's own kind, never
switching a kind the listener is using (a sermon link must not go through
the mission check). It posts `/submissions/video` with `type`: `preaching`,
`testimony_video` (`testimony` is a listener's *recorded* testimony),
`mission` or `film` — one rate limit for all (`/submissions/preaching` stays
for apps loaded before). Each is a YouTube link like a song request, held to
its own length (`<TYPE>_MIN/MAX_SECONDS`; without them `Moderator::VIDEO_LIMITS`:
5–90 min, 2–60 min, 3–90 min, 5 min–3 h) and judged as `moderate_<type>` by its
own rules in `Policy` (a film only from its studio, its distributor or a
ministry that offers it). A video program may take any of the four types (a
mission program testimonies too); every other format drops them
(`Catalog::saveProgram`). A suggestion goes first, announced like a request
(a unit), and airs as the kind it was suggested as, even when its video is
in the library as another kind; a second suggestion of a video aired or
planned within six hours goes to the library instead of airing twice. Else
`Selector::video` takes the program's own kind: the longest unheard of those
not aired within a week, then a day — never within six hours (songs are
better than a preaching twice in one program). On air a video is a `song`
item of its kind, so an app that does not know the kind plays it as the
video it is. A waiting suggestion fills the queue with its whole length; one
that can no longer fit before its program ends, or whose program is no video
program any more, goes to the library at once (`Submissions::canStillAir`),
so it holds no intake closed. Because one item can run most of an hour — a
film two — the engine re-reads the tiles' states with every minute file, not
only when an item starts.

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
pressed per device (`arche.reactions`). `?install=1` (QR codes, flyers) opens
an install sheet once the welcome is closed (`lib/pwaInstall.ts`,
bible-assistant's): Chrome's own dialog behind one tap, written steps
elsewhere. The event can fire before the bundle runs, and the CSP allows no
inline script, so `public/install-event.js` catches it — only for that link,
so every other visitor keeps Chrome's own install banner.

**Big stage** (`lib/fullStage.ts`, a button at the end of the song bar). The slot
moves into a layer over the page (`StageRegion`, a portal; z 32: over the
dock, under the player and the sheets) and the player follows it there as it
follows every slot, so the video never reloads; the browser's fullscreen,
where there is one (not on iPhones), only hides its bars. YouTube's own
fullscreen stays off: it shows the video alone, without the host, the prayer
hour or a notice, and needs YouTube's controls, which seek out of the live
program. Our visuals are drawn at 800 px and scaled up, Android's browser
turns to landscape, the screen stays on, and the browser's way out (Esc,
back), Android's back button or leaving the page closes it. Below the video,
`StageBar` is the song row made small (`components/home/NowPlaying.tsx`,
shared with the card): what plays, how far, the reactions — the emoji strip
opens to their left inside the bar, never over the video — and the way out.
After 3 quiet seconds a black veil fades over it; a tap, the pointer, a key
or the next song brings it back, and the veil takes the tap that wakes it
(no reaction pressed unseen). A phone turned
sideways on Home while the radio plays opens it by itself and closes it
upright again (`followTurns`; closed by hand, it waits for the next turn).
The store apps never ask the WebView for fullscreen — Capacitor's ends it at
once — but hide the system bars; the Android app turns from build 2, and the
button shows only in shells that turn (`shellTurns`, `<html data-turns>`):
the iOS app and Android build 1 stay upright. Short, wide screens cap the
stage to the screen's height (home.css).

**Station page and privacy** (`/about`, `app/src/content/legal.ts`). The
imprint and the privacy policy describe what this code does — the data flows
(YouTube only after the join tap, OpenAI for texts/voice/transcripts/checks,
Hetzner for hosting and rooms, BunnyCDN for delivery with IP-less logs kept
three days) and the retention periods, which are
implemented in `Tick::purge` and `defaults.php`. Change code and text together.
Submissions can reveal faith or health (Art. 9 GDPR): the forms say so next
to Send. The page also carries the promised controls (withdraw YouTube
consent, delete this device's data).

**Native apps** (`app/capacitor.config.ts`, `app/src/lib/native.ts`). The
App Store and Play apps are Capacitor 8 shells that load the live site
(`server.url`), not a copy of it: a copy on `capacitor://localhost` sends
YouTube no Referer (error 153 on every song), and the API, the CDN fallback
and the clock all assume one origin. So every deploy reaches the apps at
once, and the web is always newer than some installed shell: `lib/native.ts`
alone decides the platform (the bridge Capacitor injects at document start)
and is the only way to a plugin — `hasPlugin()` first, expect the call to fail
anyway (an older shell, or an old Android WebView where our CSP blocks
Capacitor's injected script), keep the web behaviour as the fallback, load
plugin code only through `import()`. In the apps: the radio leaves when the
app goes to the background (YouTube's terms; no background audio mode), the
screen stays on while listening (KeepAwake), the status bar follows the theme,
Android's back button closes the newest sheet or picker (`lib/backStack.ts`),
then goes back, then Home, then minimizes. iOS's WKWebView runs no service
worker (no App-Bound Domains): `pwaUpdate.ts` compares the page's entry
script instead. Program reminders are local notifications planned from the
day files (`lib/reminderPlan.ts`, pure; `reminderRunner.ts` carries the plan
out), phone-only. Store texts and privacy answers: `STORE.md`.

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
Deleting an account (`DELETE /api/me`, Profile and `/konto-loeschen`, also with
the 12 words from any browser; the app stores require it) is `Identity\Erasure`:
the identity with its aliases, its submissions and files, the host breaks
that name it (`HostBreaks::forget`), its voices and reports. Under the publish
lock or not at all (503 `busy`): what it handed in leaves the program
(`Timeline::withdraw`) — drafts dropped with their unit, committed items not
yet over blocked through live.json, every payload scrubbed (dropped rows too),
`submission_id` nulled (SQLite reuses row ids); published minute files stay.
Others' breaks name it too: one reacting to its request (`previous_id`) or
quoting its community voice (`community_by`, both kept from the model) is
drafted afresh when a draft (`HostBreaks::rewrite`: the other listener's unit
keeps its place), blocked when committed; its voices leave every payload
(`Timeline::scrubVoices`). Jobs save only while their break is pending
(`saveIfPending`); `Erasure::sweep` scrubs what was written back for 15
minutes; the chat nodes get `forget`.

**Reports and blocking** (`Moderation\Reports`, `app/src/lib/reports.ts`,
`store/blocks.ts`). Listeners report chat messages (through the room), wall
requests and community voices (the API); three different reporters known
for a day (device ids cost nothing) take a wall request down at once
(`hidden = 2`) until a moderator keeps it — for good — or takes it down
(/mod › Review › Prayer wall). Blocking someone in a room is per
device, files a `blocked` report, and hides their messages and voices too —
published voices carry `by`, a hash of the author's id (`Presence::voiceTag`
= `lib/blocking.ts voiceTag`). Display names pass the moderators' word list
(`Moderation\Blocklist`). The community rules (`content/rules.ts`,
`RULES_VERSION`) are accepted once per device before the first post.

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

- **A migration must survive a replay.** Tests set `schema` back and migrate
  again on a current database: a new version uses `IF NOT EXISTS`, guards its
  seeds, and never `ADD COLUMN`s to a table no later version rebuilds (SQLite
  has no `ADD COLUMN IF NOT EXISTS`) — a moment's host lives in its context.
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
- **A failed SFTP read is not an empty server.** A dropped session once
  listed nothing, the deploy took that for a bare server and uploaded all
  ~2,900 files: ticks paused for five minutes, two minute files were never
  written. `deploy.sh` retries the manifest and the listing once and stops
  before touching the server when they still fail.
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

- **The store apps load the exact origin.** `server.url` has no path and no
  trailing slash: Android injects the bridge only into that origin (a
  redirect to another host loses every plugin), iOS treats only URLs starting
  with it as the app. Release builds refuse any other URL in the synced
  config (Xcode build phase, Gradle `preReleaseBuild`): `cap run -l` writes a
  dev URL there — run `npm run native:sync` before archiving.
- **Never await a Capacitor plugin object.** It is a Proxy that answers every
  property, `then` included: a promise resolved with it never settles.
  `native.ts plugin()` hands out the module instead.
- **Android's offline page has no plugins** (it is served from
  `https://localhost`, outside the bridge's origin): the splash hides itself
  after 3 s. On iOS the bridge reaches every frame's message handler; the CSP's
  `frame-src` is what keeps other frames out.
- **@capacitor/local-notifications 8.3+**: `schedule()` asks for permission by
  itself (so nothing is scheduled unless permission is already granted — a
  start must never pop the question), and `isExactNotification` defaults to
  true, which opens Android's alarm settings: always state it. Without
  "Alarms & reminders" (off by default from Android 14) Android may deliver
  a reminder up to an hour late — seen on the emulator: due 23:31, shown
  23:34–23:37. With a reminder on, `ReminderToggle` offers the setting
  (`allowOnTimeReminders`); exactness is part of each reminder's signature,
  so all are written again.
- **Nothing opens over the stage.** An emoji picker or channel list above the
  player's row would sit in front of the YouTube player (z 35, fixed): pickers
  open downward, the channel list is a sheet. A modal (sheet, the welcome
  `<dialog>`) uses `useOverlay` (`lib/backStack.ts`): the video pauses under
  it, and Android's back button closes it.
- **No `container-type` above the fixed layers.** Its layout containment makes
  the element the containing block for `position: fixed` — the phone dock and
  scenery would stick to the page instead of the screen (the preview uses
  container queries only because it sits in a demo frame).
- **The phone scenery at the bottom is Home's only**: behind the other pages'
  text, which has no cards, it took the words away.
- **A pause the engine did not make is the listener's Pause.** A click on the
  video (desktop YouTube pauses on a click even without controls), the lock
  screen, headphones or a call pause the players behind the engine; left
  "playing", the radio started again by itself at the next re-entry (a sheet
  closed, the stage back in view) or segment. The engine now leaves on such a
  pause, stops anything that plays while the listener is out, and maps the
  media keys to join/leave. YouTube's `seekTo` starts a stopped video: no
  drift seek for a listener who is out.
- **…but our clip is paused while it loads.** A host clip is fetched ahead
  only when its break is known as the song before it starts — not after a
  song longer than the five fixed minutes. Loading, the element is paused,
  and the rule above left the radio at the start of the break, for everyone
  (seen on production 2026-10-05, every browser). `HostAudio` counts a clip
  as paused from outside only once its start has settled, and a start we
  overtook ourselves (a re-entry) is no refusal: that one put "Tap to resume"
  over a host who went on speaking.

## Testing

"A test is earned by a risk." Server: `npm run test:php` (enoch-style harness,
`server/tests/cases/*`, stub AI, fixed clock) covers plan resolution, the
generator's timing invariants, the PHP→fixture contract (a prayer hour's
files too), identity, submissions (request blocks, late approvals, the queue
sweep, intake times, the prayer wall, typed prayers read out word for word or
given back, one taken off the wall never read, listeners' prayers: consent,
limits, never on the wall; praying along), the host never praying
(`host.php`: the rule in the prompt, the guard, every template and lead-in,
lead-ins that change, readings voiced once and outside the daily cap, nobody
named for a sender who stayed anonymous),
deleting an account
(`erasure.php`: aliases and the words, a voiced draft never airs, a committed
request blocked with minute files byte for byte, an aired recording and the
day files, a request read next to another listener's, a listener's prayers
(a written one planned, a spoken one on air), others' breaks that react to it
or quote its voice, another listener's request kept in place, the lock, a script
finished after its break was forgotten, guards, the sweep, the nodes'
`forget`), reports (`reports.php`: names against the word list, wall reports
and their auto-hide — new devices and a kept request excluded —, the
moderators' decisions, voice reports, purges), the prayer hour walked hour
by hour (`prayerhour.php`: the running order, songs then prayer music, the
presentation word for word and its cutoff, listeners' prayers read and played
with their gaps, the encouragement, the outro without a blessing, intake for
prayers only from the committed announcement, the wall as it is read, a late
reading's short wait and its two tries, the empty hour, midnight, outages,
last-minute and repeated plan changes, nobody listening, bursts, a short
hour, the fallback rule, opening prayers, the settings migration), Open Doors
(`opendoors.php`: the feed read safely, one translation, a feed down, both
hours of a day, the station's identity), preaching programs (`preaching.php`: the running order, a
suggestion first and never twice, what no longer fits, intake and the queue,
the length limits, the library by link, the migration), the other video
programs (`videos.php`: testimonies, mission videos and films each of their
own kind, a two-hour film inside its block and across midnight, one endpoint
for a mission program that also takes testimonies, a suggestion airing as
what it was suggested as, format changes, a video suggested twice, the
limits per type and the one rate limit, /mod's kinds, the migration, the
host's words per kind, every format in every list), library groups
(`groups.php`: the notice after a group's item — the model gets the group,
never its id —, items joining by channel, one channel one group, blocking by
channel, by a fan upload's artist and by a library item, never overruled,
pulled from air and back, the channel backfill in one call, links https only,
the migration), hosts (`hosts.php`: the migration from the channels' hosts and
its replay, roles, the key sealed and never shown, what a save checks, the
delete guards, lineups an older tab leaves alone, one host per show and not
the last one's, who steps in, stub mode without a request, the ElevenLabs
quota answered by the same persona on OpenAI with its request bodies, a
rewrite for another name, retries and rests, the tick's own timeout, what
listeners see, "Try voice" and the catalog), recorded lines (`lines.php`: written
by the AI where the library is used, under their own usage and never the daily
cap, praying, overlong and twice-written lines dropped, the AI's pick and the
fallback without it, rotation, the time tag, the voice, the program's own
first, a host who cannot speak still airing them while cap and budget stop
fresh words, files outliving every break, a deleted host's or program's going
along, the ElevenLabs allowance, the prayer hour's encouragements, /mod and its
roles, a program's mode written only when sent, the replay), prayer music, moderation fail-closed, realtime tokens/reports/wake/reaper, the CDN (log count,
purge queue), and the API. App: `npm test` (Vitest: engine sync/drift/ads/evergreen,
pauses from outside and nothing playing while the listener is out, prayer music's fades and continuing pieces,
the tiles following the minute files through a long preaching, timeline, clock, i18n keys (and every key
built from the shared lists), passphrase,
realtime client, CDN fallback, theme, the phone carousel's fit, the prayer wall's
day, "On air now", the page of requests and the station's translated one, a
request shown from its reading on, the pulse's voice reactions; jsdom: the
stage's prayer view (buttons by the minute file, the count, the page, a
reading, a listener's prayer as theirs), the Pray sheet (recorder first,
written instead, both endpoints, the yes never ticked in advance, staying
anonymous), our audio not starting a clip over in its quiet, nor taken for paused while it loads, nor refused when a re-entry overtakes its start, the prayer
sheet's wall box (and in a prayer hour, the wall after it), the video
sheet's picker (open kinds only, the program's own kind first, a kind in use
kept with its notice) and `allowedForFormat`, the stage's notice card (its
links and labels, none on the compact stage), the big stage (the slot there
and back, the browser's way out, no Fullscreen API, the page left, a phone's
turns, Android's back; its bar veiled when quiet, back on a move or the next
song, kept while the emoji strip is open) and which shells turn, "Stay anonymous"
on every form hiding name and place and sending neither, and the
rules on the first post, the install sheet's single-use prompt, /mod › Lines (the
filters' query, play, recording again only after a confirm, bulk selection,
the options for admins only, the write form) and a program's "host's words"
sent only as it came; the store apps: platform
detection against @capacitor/core, plugins an older shell lacks, the status
bar table, the back stack and sheets closing newest first, the background
signal only in the apps, the entry script for iOS updates, reminder plans
(every airing, a run across midnight, DST, the 64 iOS keeps, a moved or
removed program, stale day files, ids) and the runner's plugin traps; block
filters and the author mark shared with PHP; every stored key on the delete
list). Shared:
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
test), an install link after the welcome, the theme following the device until Profile picks one, the pinned
phone player and the tiles unfolding, a prayer on the prayer wall (with its
sender's first name and place; a second listener prays along, the sender sees the count),
no sideways scroll and the stage uncovered in both themes, the big stage
(the video larger above its bar, uncovered — the emoji strip too —, the one
player, the veil taking the first click, back in place; a touch phone turned
sideways and back), chat between two
listeners, the program read cross-origin from
the stand-in CDN (CSP included) and from the site when the CDN is down, a
prayer hour on a channel of its own (prayer music on the stage, a request from
the stage counted but not shown before it is read out, no Pray button before
the prayer time, no sideways scroll), video programs on channels of their own (the
library's preaching on air as its video, named a preaching, and a preaching
suggested through the sheet; a mission program's own video named "Mission",
the sheet opening on Mission and a testimony suggested through the picker;
the tile closed on a music program), a stand-in
store-app shell (no install offer, the background stops the radio, failing
plugins break nothing), deleting an account from Profile and with the 12
words on `/konto-loeschen`, blocking between two listeners (reported to /mod,
unblocked in Profile), the rules before the first message, a wall
request reported (hidden for the reporter, in front of the moderators, down
and back by their decision), and recorded lines (encouragements written by the
stub writer go on air by themselves, one paused, a program set to recorded
lines). `npm run e2e:reset` starts over.

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

A custom hostname for the CDN zone, clearing generated clips from the ElevenLabs
history automatically, co-hosted moments (two hosts in one break), archive *replay* (slot files
are kept 48 h; `days/*.json` keep what played), phone background
playback (not possible with YouTube embeds), push notifications (the apps
have local reminders only), App/Universal Links, the iPad layout in the iOS
app, a monochrome (themed) Android icon. Pending on the host: the Phase 0.5
probe (background run length → `TICK_BUDGET`, WAL, directives) and the device
sync spike on a real iPhone/Android, and there the prayer music (it plays after
the join tap, fades, goes on across pieces, comes back after the background).
More program formats with a running order beyond the prayer hour.

OpenAI switches off its speech models (`tts-1`, `tts-1-hd`, `gpt-4o-mini-tts`)
on 2027-01-06 (announced 2026-10-01); its named successor,
`gpt-realtime-2.1-mini`, has no `/v1/audio/speech` and no MP3. An OpenAI host
then fails with a lasting error and rests, so only ElevenLabs hosts speak —
unless OpenAI offers a successor: the host editor takes any model id.
`gpt-5-mini` goes on 2026-12-11 and `gpt-4o-transcribe` on 2027-02-26; the
defaults are `gpt-5.4-mini` and `gpt-transcribe` (which takes `languages[]`).
