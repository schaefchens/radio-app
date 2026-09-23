# ARCHE – Technical Concept

## Vision
ARCHE is a community-driven Christian live radio experience built as a Progressive Web App (PWA).  
Everyone listens to and watches the same program at roughly the same time.  
Music is primarily delivered through synchronized YouTube embeds.  
The experience combines a global shared program with personal language and small community rooms.

---

## Core Principles

- **One global program** – All listeners experience the same timeline
- **Deterministic playback** – The program is driven by time-based JSON files on a CDN
- **Realtime is optional** – Chat, reactions and presence can fail without breaking the radio
- **Graceful degradation** – The radio must continue working even if secondary systems are down
- **Scalable by design** – Static program content is served via CDN (BunnyCDN)

---

## High-Level Architecture

### 1. Program System (Core – Must always work)
- Generates time-based program slots
- Creates AI host moderation audio
- Publishes JSON files to BunnyCDN
- Runs on cheap Hetzner webhosting S (PHP + SQLite)
- we have one cron job which might invoke regular actions

### 2. Submission System (Independent)
- Independent but might also be on the same webspace hosted as 1. program system.
- Handles song requests
- Handles audio stories / prayer requests
- Moderation pipeline (automatic + optional human review)
- Feeds approved content into the Program System

### 3. Realtime System (Optional Enhancement)
- WebSocket server (Node.js)
- Listener presence / count
- Chat rooms
- Reactions
- Runs on a separate Hetzner Cloud (e.g. cheap CX22)
- Must never be a critical dependency for playback
- Scales up/down with hetzner cloud api, to a defined maximum number and a minimum number of 0
- If nothing is going on, there is no cloud server, saving money.
- scaling up is by user request, if they want to use the feature dynamically the cloud server is created from snapshot which will take like 20-30 sec

### 4. Apps
- we want a pwa app which relies on offline and caches like a normal installation and fetches and sends only data
- require change and reload mechanics 
- later capacitor apps for android and ios might come
- app has embedded youtube players which are synchronized.
- youtube player must not be overlayed for usage reasons applied by youtube.
- we have a stage which may fade out and player in.
- stage is program dependent. might just show a image or more dynamic things like flyin user messages
- user login is optional and no email/password. we use 12-word crytpo wallet style passphrases the user has to remember.

### 5. Content Library & Trend System (Fallback + Engagement Data)
- Persistent pool of playable content (primarily music) used to fill program gaps when fresh user submissions are insufficient for a slot.
- Populated two ways:
  - **Curated seed content** – added manually by moderators/admins to bootstrap the pool.
  - **Graduated submissions** – approved user submissions don't disappear after their one live play; they enter the library **automatically** on approval (no separate moderator promotion step) and remain eligible for future fallback use.
- Runs as an extension of the Submission System (same storage), since that system already owns approval/moderation.
- **Metadata per item**: YouTube reference + duration, mood/theme tags (worship, upbeat, reflective, prayer, christmas, etc. – largely reused from the existing moderation pipeline's Christian-relevance/theme-relevance checks rather than a separate tagging pass), language, which Plan program types it's suitable for, and play history (last played, play count) to avoid repetition.
- **Gap-fill selection**: the Generation layer queries the library filtered by the current Program's allowed themes/moods and language, weighted toward high trend score but with enough randomness to avoid staleness.
- **Listener reactions → trend data**: listeners react to what's currently playing via the Realtime System. This must not become a precise per-reaction write to the backend – same scaling problem as a heartbeat ping. Instead:
  - Each Realtime node aggregates reactions in memory per content item while it's live.
  - Aggregated counts flush as one batched delta every ~15–30s, not per reaction – O(time-intervals) writes instead of O(reactions), independent of listener count.
  - If multiple Realtime nodes exist later (sharded rooms), their batched deltas are simply additive at flush time – no redesign needed to scale horizontally.
  - Trend score applies time-decay (rolling weighted window) so it reflects recent reception, not a lifetime tally.
  - This is a soft ranking signal for AI fallback selection, not a public leaderboard – we want trends, not precise counts; performance and scalability outrank exactness here.
  - Reaction collection rides on the (optional, scale-to-zero) Realtime System, so it's itself optional – if Realtime is down, gap-filling still works, just without trend weighting for that period.

---

## Technology Stack

| Layer              | Technology                          | Hosting                     |
|--------------------|-------------------------------------|-----------------------------|
| Frontend           | React + Vite (+ Capacitor later)    | BunnyCDN / Static           |
| Main Backend       | PHP                                 | Hetzner Webhosting          |
| Database           | SQLite                              | Hetzner Webhosting          |
| Realtime Server    | Node.js (WebSockets)                | Hetzner Cloud CX22          |
| CDN                | BunnyCDN                            | -                           |
| AI Voice           | ElevenLabs (v3 or Turbo) or for dev openai | -                           |
| Video              | YouTube Embed (synchronized)        | -                           |

## Performance and Scalability
the radio listening experience is meant to be scalable to the millions. the submission and realtime systems might be less. So architecture from ground up must fullfil the scale requirements even if in the beginning very cheap hosting is used. technology should also be planned to be separated by concerns and features accordingly.

---

## Program Slot System
the program is the core of the app. it will control what the user hears and sees. its like timelines nowing when to play and show what. its pregenerated and uploaded to cdn. this allows scaling to millions of users.
the user knows the time and can get all relevant info deterministic.
there might be one file per minute or more time gap. the lower the time gap the more realtime it feels.


### Submission Status Values
- `open` → Still accepting submissions
- `closing` → Last chance
- `closed` → No more submissions for the upcoming segment

---

## Synchronization Strategy

The entire radio is **deterministic** and driven by UTC time + CDN files.

1. User creates a session (login or anonymous)
2. Server returns `server_time` together with the session
3. Client calculates clock offset once
4. Client derives the current 1-minute slot from the corrected time
5. Client loads the corresponding JSON from BunnyCDN
6. Client calculates the exact position inside the slot and seeks the YouTube player accordingly
7. Periodic light correction every 20–30 seconds to counter drift

**No continuous server connection is required for program playback.**

---

## AI Host Generation

- Generated on demand (or a few minutes ahead)
- Can be reduced or paused when very few listeners are present
- Audio files are stored on BunnyCDN and referenced in the slot JSON
- Supports multiple languages (separate audio files or language variants)
- for development we might instead stay on hetzner webspace and use that for delivering the program files.

---

## Contribution Flow

### Identity
- Every install generates an **anonymous identity** locally on first use; stored on-device, sent with every request. Server-side this anon ID is a full identity record from day one — same shape as a passphrase identity, just without a passphrase attached yet.
- A 12-word passphrase is an optional **credential** attached to an identity later — not a separate identity type.
- **Claiming**: entering/creating a passphrase sends a claim call; the server records anon-ID → passphrase-identity in a lightweight alias table. Submission/history rows never move — lookups resolve through the alias table first. One passphrase identity can accumulate several claimed anon IDs (multi-device use).
- **Purge**: unclaimed anon IDs with no activity for 30–90 days are eligible for deletion. Claimed anon IDs are never purged independently — they're pointers; the real record lives at the identity they resolve to.
- Known gap (not solved by this): prevents identity *fragmentation* for legitimate users, but doesn't stop someone deliberately cycling anon IDs to dodge rate limits — that needs a separate IP/device-signal defense, tracked as an open item.

### Submission steps
1. **Eligibility (client-side)** – the slot JSON the client already has for playback sync also carries the current Program's allowed submission types and `open`/`closing`/`closed` state. The submission UI only ever shows what's currently allowed — no extra API call.
2. **Entry** – song: paste YouTube URL, client validates ID + duration via oEmbed before allowing submit. Audio (story/prayer/testimony/greeting): in-browser recording, capped duration, listen-back before send.
3. **Silent vs. announced songs** – a song submitted with no message is *silent*: if it passes checks it enters the pool as a plain track, no AI host script. A song submitted with a message gets an extra check on whether the message makes sense/fits; if it passes, the submission is tagged announcement-eligible so the Generation layer can write a host transition around it. If the song is fine but the message fails its check, the **whole submission is rejected** (not silently downgraded to silent) — the submitter is told, rather than having their message quietly dropped.
4. **Intake** – raw submission + program/slot context + timestamp persisted; rate-limited per identity/session (the primary anti-abuse layer given there's no email verification).
5. **Moderation – two stages**:
   - **Baseline** (always runs): safety, legality, general Christian relevance.
   - **Per-Program** (pulled from the current Program's Plan entry): allowed submission types and allowed theme/mood tags. A song submitted during Prayer Hour fails here on type mismatch, before content is even evaluated.
   - Users can only submit to whichever Program is currently on air — enforced already by the eligibility check in step 1.
6. **Timing edge case** – if a submission needed human review and isn't approved until after its Program's window has closed, it can no longer be scheduled live for that slot. Default: it still graduates into the Content Library, tagged for that program type, and becomes eligible the next time a matching Program runs, rather than being wasted.
7. **On approval** – enters the Program System's scheduling pool for its slot and, per the automatic-graduation decision, immediately enters the Content Library carrying the tags moderation produced. Only theme/mood tags carry over — a submitter's personal message (e.g. a dedication) is submission-specific context, not a reusable library tag, so it never resurfaces if the song is replayed later from the fallback pool.
8. **Status feedback** – pending/aired/rejected, looked up by identity, polled when the app is open (not realtime-dependent). Rejections get one of a small fixed set of generic reasons (e.g. *doesn't match current program* / *content not suitable* / *not accepted this time*) — deliberately vague so the specific classifier signals that would let someone learn to route around moderation are never exposed.

---

## System Separation Rules

- The **Program System** must never depend on the Realtime System.
- The **Submission System** feeds the Program System asynchronously.
- The **Realtime System** may influence AI richness (listener count) and run other algorithmus for content/progran control  but is never required.
- If the WebSocket server goes down, the radio continues normally. Only chat/reactions/presence are affected.

---

## Decisions & Open Questions (Log)

Running log of concept decisions made during design discussion, so context isn't lost.

| Topic | Decision |
|---|---|
| Language selection | Detected client-side (browser locale), defaulting to `en` unless `de`. Not server-tracked – keeps the shared timeline anonymous by default. |
| YouTube pre-roll ads | Accepted as unavoidable; the periodic resync (20–30s) absorbs the drift. Listeners wanting an ad-free experience are responsible for their own YouTube Premium. |
| Mobile/background throttling | Client forces an immediate resync on `visibilitychange` (tab/app foregrounded), rather than waiting for the next periodic tick. |
| Autoplay restrictions | Joining mid-stream requires a "tap to join" gesture on the stage – never a silent auto-join with sound. |
| Regional video availability | Slot JSON includes a fallback (jingle/silence) the client switches to if the YouTube player errors on the primary video. |
| Listener count at scale | Derived from BunnyCDN request-log data for the current slot's file path (near-real-time via BunnyCDN's logging API), not client pings to the origin. Each client already fetches that file once per slot as part of normal sync, so this is free – zero added origin load – and works even with the Realtime System scaled to zero. When Realtime is active, it can additionally expose an exact per-room count layered on top. |
| Passphrase auth | 12 words generated client-side, hashed (e.g. Argon2) before reaching the server. No recovery path by design – UI must be explicit the user is solely responsible for saving it. |
| Plan vs. AI moderation boundary | Two named layers: **Plan** (fixed structure – time slots, program type, allowed submission types; editable only by restricted users) and **Generation** (AI works within a slot's Plan constraints – order, featuring submissions, moderation script, fly-ins). The AI can never act outside what the Plan allows for that slot. |
| Content gap-filling | See "Content Library & Trend System" above – dedicated pool of music/content, enriched by graduated submissions, tagged with mood/theme metadata, selected by the Generation layer when fresh submissions run short. |
| Submission graduation | Automatic – any approved submission enters the Content Library immediately on moderation approval, no separate promotion step. Keeps the pool growing on its own as the show runs. |
| Identity handling | See "Contribution Flow → Identity" above – anonymous-first; a passphrase is an optional credential attached later via an alias table, so no identity fragmentation between anonymous and account use. |
| Per-program moderation | See "Contribution Flow → Submission steps" above – two-stage pipeline: universal baseline, then per-Program allowed types/themes pulled from that Program's Plan entry. |
| Rejection feedback | A small fixed set of generic reasons only (e.g. doesn't match current program / not suitable / not accepted this time) – specific classifier signals are never exposed, to prevent moderation gaming. |
| Silent vs. announced songs | Silent (link-only) songs entering the pool get no AI script. Songs with a message get an extra fit-check; if the song passes but the message fails, the whole submission is rejected rather than silently downgraded to silent. |

---

**Principle:**
> One global program. Personal language. Small communities. Worldwide connection.  
> The radio must keep playing — even when everything else fails.
