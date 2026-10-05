<?php
declare(strict_types=1);

// Defaults for every setting Config::get() knows. The environment (process env,
// then the .env file) overrides these; nothing here is secret.
return [
    'ARCHE_ENV' => 'production',
    'SITE_BASE_URL' => 'https://radio.schaefchens.de',

    // --- AI -------------------------------------------------------------------
    // stub = deterministic, no network (tests, offline dev). live = real calls.
    'AI_MODE' => 'live',
    // Who writes the host's words and judges submissions: anthropic | openai |
    // auto (Anthropic when ANTHROPIC_KEY is set, else OpenAI when OPENAI_KEY is).
    'AI_TEXT_PROVIDER' => 'auto',
    'HOST_MODEL' => 'claude-opus-5',
    'MODERATION_MODEL' => 'claude-opus-5',
    'OPENAI_HOST_MODEL' => 'gpt-5-mini',
    'OPENAI_MODERATION_MODEL' => 'gpt-5-mini',
    // Jingles made with OpenAI's voice in /mod. The hosts' voices — OpenAI or
    // ElevenLabs, model, voice, key, a daily cap — are set per host in /mod › Hosts.
    'TTS_MODEL' => 'gpt-4o-mini-tts',
    'STT_MODEL' => 'gpt-4o-transcribe',
    'STATION_LANGS' => 'en,de',
    // Rough USD cap across all AI calls per UTC day. Host breaks and
    // moderation both stop (template / reject-later) when it is reached.
    'AI_DAILY_BUDGET_USD' => '5',
    'HOST_MAX_BREAKS_PER_DAY' => '300',
    // Below this many listeners a channel plays music only: no AI cost while
    // nobody is there (an idle dev deploy costs nothing).
    'HOST_MIN_LISTENERS' => '1',
    'MODERATION_MAX_PER_DAY' => '300',
    'MODERATION_HUMAN_REVIEW' => '0',

    // --- tick ------------------------------------------------------------------
    // Seconds of network time one tick may spend after the cron request has
    // been answered. Measured on the host by the Phase 0.5 probe.
    'TICK_BUDGET' => '22',
    'SQLITE_WAL' => '1',

    // --- listeners -------------------------------------------------------------
    'PULSE_SECONDS' => '120',
    'PRESENCE_WINDOW_SECONDS' => '300',

    // --- retention -------------------------------------------------------------
    'RETAIN_SLOT_HOURS' => '48',
    'RETAIN_HOST_AUDIO_HOURS' => '48',
    'RETAIN_DAY_FILES_DAYS' => '60',
    'RETAIN_TIMELINE_DAYS' => '30',
    // Stated in the privacy policy (app/src/content/legal.ts): change both together.
    'RETAIN_SUBMISSIONS_DAYS' => '90',

    // --- submissions -----------------------------------------------------------
    'SONG_MIN_SECONDS' => '60',
    'SONG_MAX_SECONDS' => '720',
    // A listener's suggested videos (the video sheet), by type: a sermon or a
    // devotion; a testimony on YouTube (a *recorded* testimony is
    // AUDIO_MAX_SECONDS); a mission video; a film, which runs two hours and more.
    'PREACHING_MIN_SECONDS' => '300',
    'PREACHING_MAX_SECONDS' => '5400',
    'TESTIMONY_VIDEO_MIN_SECONDS' => '120',
    'TESTIMONY_VIDEO_MAX_SECONDS' => '3600',
    'MISSION_MIN_SECONDS' => '180',
    'MISSION_MAX_SECONDS' => '5400',
    'FILM_MIN_SECONDS' => '300',
    'FILM_MAX_SECONDS' => '10800',
    'AUDIO_MAX_SECONDS' => '90',
    'GREETING_MAX_SECONDS' => '60',
    // A listener's spoken prayer in a prayer hour's prayer time.
    'PRAYER_MAX_SECONDS' => '60',
    'SUBMISSION_MARKETS' => 'DE,US,GB',
    // Per shared address (a church Wi-Fi, a carrier's CGNAT): per-identity
    // limits, MODERATION_MAX_PER_DAY and the AI budget bound the cost anyway.
    'SUBMISSIONS_PER_IP_HOUR' => '60',
    // 🙏 on prayer wall requests from one address an hour that count (one per
    // device and request anyway): a church group on one Wi-Fi prays a lot.
    'PRAY_ALONG_PER_IP_HOUR' => '600',
    // Reports of other listeners' content from one address an hour (Moderation\Reports).
    'REPORTS_PER_IP_HOUR' => '60',
    // Different listeners whose reports take a wall request down until a
    // moderator decides; 0 = only moderators take requests down.
    'WALL_REPORTS_HIDE' => '3',
    'IDENTITIES_PER_IP_DAY' => '300',
    // The e2e stack points this at a fake (app/tests/e2e/fake-youtube.mjs).
    'YOUTUBE_API_BASE' => 'https://www.googleapis.com/youtube/v3',
    // Open Doors Deutschland's daily prayer request, read first in every prayer
    // hour (a program setting can leave it out). `off` turns it off everywhere.
    'OPENDOORS_FEED_URL' => 'https://www.opendoors.de/rss/gebet',

    // --- CDN (scripts/cdn/setup-bunny.sh) ---------------------------------------
    // Base URL the app loads /program and /media from; '' = the origin.
    'CDN_BASE_URL' => '',
    'BUNNY_PULL_ZONE_ID' => '',

    // --- realtime --------------------------------------------------------------
    // off (no rooms: the app hides chat) | static (one fixed node: the local
    // compose service, REALTIME_STATIC_URL) | hcloud (scale-to-zero nodes).
    // Off by default: a deployed station has rooms only once the Hetzner nodes
    // are set up — a `static` default would send listeners to localhost.
    'REALTIME_DRIVER' => 'off',
    'REALTIME_STATIC_URL' => 'ws://localhost:8787/ws',
    'REALTIME_MAX_NODES' => '1',
    // One architecture for both (the snapshot is built for one). Arm (cax*)
    // was sold out in every location in September 2026, so x86 it is; the
    // fallback is tried when Hetzner refuses the first type.
    'REALTIME_SERVER_TYPE' => 'cpx12',
    'REALTIME_FALLBACK_TYPE' => 'cpx22',
    'REALTIME_LOCATION' => 'fsn1',
    'REALTIME_IDLE_MINUTES' => '10',
    'REALTIME_SILENT_MINUTES' => '3',
    'REALTIME_MAX_LIFETIME_HOURS' => '12',
    'REALTIME_NODE_CAPACITY' => '800',
    'REALTIME_ACME_EMAIL' => '',
    // An SSH key of the Hetzner project (id or name) for the nodes: without
    // one Hetzner mails a root password for every node created.
    'REALTIME_SSH_KEY' => '',
];
