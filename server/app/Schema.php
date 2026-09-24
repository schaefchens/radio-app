<?php
declare(strict_types=1);

namespace Arche;

/**
 * Versioned migrations. The applied version lives in kv('schema'); every
 * request runs `migrate()`, which is one SELECT once the database is current.
 * Add a new version at the end — never edit an applied one.
 */
final class Schema
{
    public static function migrate(Store $store, int $nowMs): void
    {
        $store->db->exec('CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $current = (int) ($store->get('schema') ?? 0);
        $versions = self::versions();
        if ($current >= count($versions)) return;

        $store->tx(function () use ($store, $versions, $nowMs) {
            // Re-read inside the lock: two requests may race to the first run.
            $current = (int) ($store->get('schema') ?? 0);
            foreach ($versions as $i => $sql) {
                $version = $i + 1;
                if ($version <= $current) continue;
                $store->db->exec($sql);
                $store->set('schema', $version);
            }
            Seeder::ensureDefaults($store, $nowMs);
        });
    }

    /** @return list<string> */
    private static function versions(): array
    {
        return [
            // 1 — everything the first release needs.
            <<<'SQL'
            CREATE TABLE audit (id INTEGER PRIMARY KEY, time INTEGER NOT NULL, actor TEXT NOT NULL, event TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
            CREATE INDEX audit_time ON audit(time);
            CREATE TABLE attempts (key TEXT NOT NULL, time INTEGER NOT NULL);
            CREATE INDEX attempts_key_time ON attempts(key, time);

            CREATE TABLE channels (
              id INTEGER PRIMARY KEY,
              slug TEXT NOT NULL UNIQUE,
              name_en TEXT NOT NULL,
              name_de TEXT NOT NULL,
              is_main INTEGER NOT NULL DEFAULT 0,
              timezone TEXT NOT NULL DEFAULT 'Europe/Berlin',
              color TEXT NOT NULL DEFAULT '#2f7bff',
              sort INTEGER NOT NULL DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1,
              host_name TEXT NOT NULL DEFAULT 'Hope',
              host_avatar TEXT,
              host_voice_en TEXT NOT NULL DEFAULT 'coral',
              host_voice_de TEXT NOT NULL DEFAULT 'coral',
              host_style TEXT NOT NULL DEFAULT '',
              default_day_plan_id INTEGER,
              fallback_program_id INTEGER,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );

            CREATE TABLE programs (
              id INTEGER PRIMARY KEY,
              channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
              slug TEXT NOT NULL,
              title_en TEXT NOT NULL,
              title_de TEXT NOT NULL,
              subtitle_en TEXT NOT NULL DEFAULT '',
              subtitle_de TEXT NOT NULL DEFAULT '',
              description_en TEXT NOT NULL DEFAULT '',
              description_de TEXT NOT NULL DEFAULT '',
              tagline_en TEXT NOT NULL DEFAULT '',
              tagline_de TEXT NOT NULL DEFAULT '',
              color TEXT NOT NULL DEFAULT '#2f7bff',
              image TEXT,
              stage_mode TEXT NOT NULL DEFAULT 'ambient',
              allowed TEXT NOT NULL DEFAULT '[]',
              themes TEXT NOT NULL DEFAULT '[]',
              moods TEXT NOT NULL DEFAULT '[]',
              settings TEXT NOT NULL DEFAULT '{}',
              active INTEGER NOT NULL DEFAULT 1,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL,
              UNIQUE(channel_id, slug)
            );

            CREATE TABLE day_plans (
              id INTEGER PRIMARY KEY,
              channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
              name TEXT NOT NULL,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE TABLE day_plan_blocks (
              id INTEGER PRIMARY KEY,
              day_plan_id INTEGER NOT NULL REFERENCES day_plans(id) ON DELETE CASCADE,
              start_min INTEGER NOT NULL CHECK (start_min >= 0 AND start_min < 1440),
              end_min INTEGER NOT NULL CHECK (end_min > start_min AND end_min <= 1440),
              program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE
            );
            CREATE INDEX day_plan_blocks_plan ON day_plan_blocks(day_plan_id, start_min);
            CREATE TABLE week_plan (
              channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
              weekday INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
              day_plan_id INTEGER NOT NULL REFERENCES day_plans(id) ON DELETE CASCADE,
              PRIMARY KEY (channel_id, weekday)
            );
            CREATE TABLE special_days (
              id INTEGER PRIMARY KEY,
              channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
              name TEXT NOT NULL,
              kind TEXT NOT NULL CHECK (kind IN ('date', 'easter')),
              month INTEGER,
              day INTEGER,
              year INTEGER,
              easter_offset INTEGER,
              day_plan_id INTEGER NOT NULL REFERENCES day_plans(id) ON DELETE CASCADE,
              created INTEGER NOT NULL
            );

            CREATE TABLE library_items (
              id INTEGER PRIMARY KEY,
              kind TEXT NOT NULL DEFAULT 'song' CHECK (kind IN ('song', 'jingle', 'contrib')),
              yt_id TEXT,
              audio TEXT,
              title TEXT NOT NULL,
              artist TEXT NOT NULL DEFAULT '',
              thumb TEXT,
              duration_ms INTEGER NOT NULL,
              languages TEXT NOT NULL DEFAULT '[]',
              themes TEXT NOT NULL DEFAULT '[]',
              moods TEXT NOT NULL DEFAULT '[]',
              program_ids TEXT NOT NULL DEFAULT '[]',
              channel_ids TEXT NOT NULL DEFAULT '[]',
              source TEXT NOT NULL DEFAULT 'curated',
              submission_id INTEGER,
              meta TEXT NOT NULL DEFAULT '{}',
              active INTEGER NOT NULL DEFAULT 1,
              plays INTEGER NOT NULL DEFAULT 0,
              last_played INTEGER,
              trend_score REAL NOT NULL DEFAULT 0,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE UNIQUE INDEX library_yt ON library_items(yt_id) WHERE yt_id IS NOT NULL;
            CREATE INDEX library_kind_active ON library_items(kind, active);

            CREATE TABLE timeline_items (
              id INTEGER PRIMARY KEY,
              uid TEXT NOT NULL UNIQUE,
              channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
              program_id INTEGER,
              state TEXT NOT NULL CHECK (state IN ('draft', 'committed', 'dropped')),
              seq REAL NOT NULL,
              est_start INTEGER NOT NULL,
              start_ms INTEGER,
              dur_ms INTEGER NOT NULL,
              type TEXT NOT NULL,
              unit TEXT,
              block_start INTEGER NOT NULL,
              block_end INTEGER NOT NULL,
              library_id INTEGER,
              submission_id INTEGER,
              host_break_id INTEGER,
              payload TEXT NOT NULL DEFAULT '{}',
              blocked INTEGER NOT NULL DEFAULT 0,
              created INTEGER NOT NULL
            );
            CREATE INDEX timeline_draft ON timeline_items(channel_id, state, seq);
            CREATE INDEX timeline_start ON timeline_items(channel_id, start_ms);

            CREATE TABLE host_breaks (
              id INTEGER PRIMARY KEY,
              channel_id INTEGER NOT NULL,
              program_id INTEGER,
              kind TEXT NOT NULL,
              state TEXT NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'ready', 'failed', 'cancelled')),
              context TEXT NOT NULL DEFAULT '{}',
              texts TEXT NOT NULL DEFAULT '{}',
              audio TEXT NOT NULL DEFAULT '{}',
              durations TEXT NOT NULL DEFAULT '{}',
              source TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );

            CREATE TABLE jobs (
              id INTEGER PRIMARY KEY,
              type TEXT NOT NULL,
              ref_id INTEGER NOT NULL,
              phase TEXT NOT NULL DEFAULT 'start',
              status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
              priority INTEGER NOT NULL DEFAULT 100,
              due_ms INTEGER NOT NULL DEFAULT 0,
              attempts INTEGER NOT NULL DEFAULT 0,
              lease_until INTEGER NOT NULL DEFAULT 0,
              last_error TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX jobs_ready ON jobs(status, priority, due_ms);
            CREATE UNIQUE INDEX jobs_one_active ON jobs(type, ref_id) WHERE status IN ('queued', 'running');

            CREATE TABLE identities (
              id INTEGER PRIMARY KEY,
              public_id TEXT NOT NULL UNIQUE,
              device_key TEXT UNIQUE,
              device_secret TEXT,
              cred_key TEXT UNIQUE,
              cred_hash TEXT,
              canonical_id INTEGER REFERENCES identities(id) ON DELETE SET NULL,
              display_name TEXT NOT NULL DEFAULT '',
              country TEXT NOT NULL DEFAULT '',
              lang TEXT NOT NULL DEFAULT 'en',
              role TEXT NOT NULL DEFAULT 'listener',
              banned INTEGER NOT NULL DEFAULT 0,
              created INTEGER NOT NULL,
              last_seen INTEGER NOT NULL
            );
            CREATE INDEX identities_canonical ON identities(canonical_id);

            CREATE TABLE submissions (
              id INTEGER PRIMARY KEY,
              public_id TEXT NOT NULL UNIQUE,
              identity_id INTEGER NOT NULL REFERENCES identities(id) ON DELETE CASCADE,
              channel_id INTEGER NOT NULL,
              program_id INTEGER NOT NULL,
              type TEXT NOT NULL,
              mode TEXT NOT NULL DEFAULT '',
              status TEXT NOT NULL,
              reason TEXT NOT NULL DEFAULT '',
              yt_id TEXT,
              message TEXT NOT NULL DEFAULT '',
              name TEXT NOT NULL DEFAULT '',
              place TEXT NOT NULL DEFAULT '',
              text TEXT NOT NULL DEFAULT '',
              lang TEXT NOT NULL DEFAULT 'en',
              upload TEXT,
              audio TEXT,
              audio_ms INTEGER,
              transcript TEXT NOT NULL DEFAULT '',
              consent_air INTEGER NOT NULL DEFAULT 0,
              consent_replay INTEGER NOT NULL DEFAULT 0,
              meta TEXT NOT NULL DEFAULT '{}',
              verdict TEXT NOT NULL DEFAULT '{}',
              library_id INTEGER,
              window_end INTEGER NOT NULL DEFAULT 0,
              aired_at INTEGER,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX submissions_identity ON submissions(identity_id, created);
            CREATE INDEX submissions_queue ON submissions(channel_id, program_id, status, created);

            CREATE TABLE presence (device TEXT PRIMARY KEY, channel TEXT NOT NULL, seen INTEGER NOT NULL);
            CREATE INDEX presence_seen ON presence(seen);
            CREATE TABLE reactions (
              library_id INTEGER NOT NULL,
              day TEXT NOT NULL,
              kind TEXT NOT NULL,
              n INTEGER NOT NULL DEFAULT 0,
              PRIMARY KEY (library_id, day, kind)
            );
            CREATE TABLE playback_errors (
              library_id INTEGER NOT NULL,
              reporter TEXT NOT NULL,
              code INTEGER NOT NULL,
              time INTEGER NOT NULL,
              PRIMARY KEY (library_id, reporter)
            );
            CREATE TABLE ai_usage (
              day TEXT NOT NULL,
              kind TEXT NOT NULL,
              calls INTEGER NOT NULL DEFAULT 0,
              input_tokens INTEGER NOT NULL DEFAULT 0,
              output_tokens INTEGER NOT NULL DEFAULT 0,
              cost_micros INTEGER NOT NULL DEFAULT 0,
              PRIMARY KEY (day, kind)
            );

            CREATE TABLE nodes (
              slot TEXT PRIMARY KEY,
              state TEXT NOT NULL DEFAULT 'off',
              server_id INTEGER,
              host TEXT NOT NULL DEFAULT '',
              created_at INTEGER,
              ready_at INTEGER,
              last_report INTEGER,
              last_wake INTEGER,
              connections INTEGER NOT NULL DEFAULT 0,
              empty_since INTEGER,
              draining INTEGER NOT NULL DEFAULT 0,
              info TEXT NOT NULL DEFAULT '{}',
              error TEXT NOT NULL DEFAULT '',
              updated INTEGER NOT NULL
            );
            CREATE TABLE highlights (
              id INTEGER PRIMARY KEY,
              uid TEXT NOT NULL UNIQUE,
              channel TEXT NOT NULL,
              lang TEXT NOT NULL DEFAULT 'en',
              sub TEXT NOT NULL,
              name TEXT NOT NULL,
              country TEXT NOT NULL DEFAULT '',
              text TEXT NOT NULL,
              likes INTEGER NOT NULL DEFAULT 0,
              reactions INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'candidate',
              at INTEGER NOT NULL,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX highlights_status ON highlights(channel, status, at);
            CREATE TABLE chat_reports (
              id INTEGER PRIMARY KEY,
              msg TEXT NOT NULL,
              text TEXT NOT NULL,
              author TEXT NOT NULL,
              reporter TEXT NOT NULL,
              reason TEXT NOT NULL DEFAULT '',
              status TEXT NOT NULL DEFAULT 'open',
              at INTEGER NOT NULL,
              created INTEGER NOT NULL,
              UNIQUE (msg, reporter)
            );
            CREATE TABLE removed_messages (msg TEXT PRIMARY KEY, time INTEGER NOT NULL);
            SQL,
            // 2 — requests air about 10 minutes after approval instead of 45:
            // programs saved with the old default intake times get the new
            // ones, and the drafts made 45 minutes ahead are planned again
            // (a new plan version), or new requests would wait behind them.
            <<<'SQL'
            UPDATE programs SET settings = json_set(settings, '$.closed_min', 15) WHERE json_valid(settings) AND json_extract(settings, '$.closed_min') = 20;
            UPDATE programs SET settings = json_set(settings, '$.closing_min', 25) WHERE json_valid(settings) AND json_extract(settings, '$.closing_min') = 35;
            INSERT INTO kv(key, value) VALUES('plan_version', '1')
              ON CONFLICT(key) DO UPDATE SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT);
            SQL,
        ];
    }
}
