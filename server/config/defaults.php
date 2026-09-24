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
    'TTS_MODEL' => 'gpt-4o-mini-tts',
    // openai | elevenlabs. ElevenLabs is opt-in and hard-capped per day: the
    // account behind ELEVENLABS_API_KEY is a small one. A cap of 0 means it is
    // never used, whatever TTS_PROVIDER says (OpenAI takes over).
    'TTS_PROVIDER' => 'openai',
    'ELEVENLABS_MODEL' => 'eleven_flash_v2_5',
    'ELEVENLABS_VOICE_EN' => '',
    'ELEVENLABS_VOICE_DE' => '',
    'ELEVENLABS_MAX_CHARS_PER_DAY' => '0',
    'STT_MODEL' => 'gpt-4o-transcribe',
    'STATION_LANGS' => 'en,de',
    // Rough USD cap across all AI calls per UTC day. Host breaks and
    // moderation both stop (template / reject-later) when it is reached.
    'AI_DAILY_BUDGET_USD' => '5',
    'HOST_MAX_BREAKS_PER_DAY' => '150',
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

    // --- submissions -----------------------------------------------------------
    'SONG_MIN_SECONDS' => '60',
    'SONG_MAX_SECONDS' => '720',
    'AUDIO_MAX_SECONDS' => '90',
    'GREETING_MAX_SECONDS' => '60',
    'SUBMISSION_MARKETS' => 'DE,US,GB',
    // Per shared address (a church Wi-Fi, a carrier's CGNAT): per-identity
    // limits, MODERATION_MAX_PER_DAY and the AI budget bound the cost anyway.
    'SUBMISSIONS_PER_IP_HOUR' => '60',
    'IDENTITIES_PER_IP_DAY' => '300',
    // The e2e stack points this at a fake (app/tests/e2e/fake-youtube.mjs).
    'YOUTUBE_API_BASE' => 'https://www.googleapis.com/youtube/v3',

    // --- realtime --------------------------------------------------------------
    // off (no rooms: the app hides chat) | static (one fixed node: the local
    // compose service, REALTIME_STATIC_URL) | hcloud (scale-to-zero nodes).
    // Off by default: a deployed station has rooms only once the Hetzner nodes
    // are set up — a `static` default would send listeners to localhost.
    'REALTIME_DRIVER' => 'off',
    'REALTIME_STATIC_URL' => 'ws://localhost:8787/ws',
    'REALTIME_MAX_NODES' => '1',
    'REALTIME_SERVER_TYPE' => 'cax11',
    'REALTIME_FALLBACK_TYPE' => 'cax21',
    'REALTIME_LOCATION' => 'fsn1',
    'REALTIME_IDLE_MINUTES' => '10',
    'REALTIME_SILENT_MINUTES' => '3',
    'REALTIME_MAX_LIFETIME_HOURS' => '12',
    'REALTIME_NODE_CAPACITY' => '800',
    'REALTIME_ACME_EMAIL' => '',
];
