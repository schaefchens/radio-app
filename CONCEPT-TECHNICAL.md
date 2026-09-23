```markdown
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

## System Separation Rules

- The **Program System** must never depend on the Realtime System.
- The **Submission System** feeds the Program System asynchronously.
- The **Realtime System** may influence AI richness (listener count) and run other algorithmus for content/progran control  but is never required.
- If the WebSocket server goes down, the radio continues normally. Only chat/reactions/presence are affected.

---

**Principle:**
> One global program. Personal language. Small communities. Worldwide connection.  
> The radio must keep playing — even when everything else fails.
```
