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
    /** @param ?int $upTo stop after this version (a test replays one migration on an older database) */
    public static function migrate(Store $store, int $nowMs, ?int $upTo = null): void
    {
        $store->db->exec('CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $current = (int) ($store->get('schema') ?? 0);
        $versions = self::versions();
        if ($upTo !== null) $versions = array_slice($versions, 0, $upTo);
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
            // 3 — the prayer wall: a moderator can take a typed prayer off it
            // without touching its status (it may still be prayed for on air).
            // live.json reads the wall every tick, inside the publish phase,
            // so it gets its own index instead of scanning 90 days of rows.
            <<<'SQL'
            ALTER TABLE submissions ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0;
            CREATE INDEX submissions_wall ON submissions(channel_id, created) WHERE type = 'prayer' AND mode = 'text' AND consent_air = 1;
            SQL,
            // 4 — typed prayers the host took stayed "scheduled" for good: their
            // break lost its ids to the script it was written with (it keeps
            // the texts under `prayers`). Matched by text: a break still in the
            // plan gets its ids back; one that aired gives its start; the rest
            // never aired and return to the queue, where the sweep marks those
            // too late for their program as missed.
            <<<'SQL'
            UPDATE host_breaks SET context = json_set(context, '$.prayer_ids', json((
                SELECT json_group_array(s.id) FROM json_each(host_breaks.context, '$.prayers') p
                JOIN submissions s ON s.channel_id = host_breaks.channel_id AND s.type = 'prayer' AND s.mode = 'text'
                  AND s.status = 'scheduled' AND s.aired_at IS NULL AND s.text = json_extract(p.value, '$.text')
                WHERE p.type = 'object')))
            WHERE kind = 'prayer' AND json_type(context, '$.prayer_ids') IS NULL AND json_type(context, '$.prayers[0]') = 'object'
              AND id IN (SELECT host_break_id FROM timeline_items WHERE state = 'draft' AND host_break_id IS NOT NULL);
            UPDATE submissions SET aired_at = (
                SELECT MIN(t.start_ms) FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id, json_each(h.context, '$.prayers') p
                WHERE t.state = 'committed' AND t.channel_id = submissions.channel_id AND h.kind = 'prayer'
                  AND p.type = 'object' AND json_extract(p.value, '$.text') = submissions.text)
            WHERE type = 'prayer' AND mode = 'text' AND status = 'scheduled' AND aired_at IS NULL;
            UPDATE submissions SET status = 'approved' WHERE type = 'prayer' AND mode = 'text' AND status = 'scheduled' AND aired_at IS NULL
              AND id NOT IN (
                SELECT j.value FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id, json_each(h.context, '$.prayer_ids') j
                WHERE t.state = 'draft'
                UNION
                SELECT j.value FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id, json_each(h.context, '$.prayers') j
                WHERE t.state = 'draft' AND j.type = 'integer');
            SQL,
            // 5 — background music in the library (kind `bed`: the prayer
            // hour's prayer music). SQLite cannot change a CHECK in place, so
            // the table is rebuilt; no foreign key points at it.
            <<<'SQL'
            CREATE TABLE library_items_new (
              id INTEGER PRIMARY KEY,
              kind TEXT NOT NULL DEFAULT 'song' CHECK (kind IN ('song', 'jingle', 'contrib', 'bed')),
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
            INSERT INTO library_items_new (id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated)
            SELECT id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated
            FROM library_items;
            DROP TABLE library_items;
            ALTER TABLE library_items_new RENAME TO library_items;
            CREATE UNIQUE INDEX library_yt ON library_items(yt_id) WHERE yt_id IS NOT NULL;
            CREATE INDEX library_kind_active ON library_items(kind, active);
            SQL,
            // 6 — praying along with a request on the prayer wall: each device
            // counts once per request. Who prayed for which request is kept
            // only while a wall can show it (Submissions::forgetPrayedAlong);
            // the number stays with the request.
            <<<'SQL'
            ALTER TABLE submissions ADD COLUMN prayed_count INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE prayed_along (
              submission_id INTEGER NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
              who TEXT NOT NULL,
              PRIMARY KEY (submission_id, who)
            ) WITHOUT ROWID;
            SQL,
            // 7 — opening prayers a moderator prepared for a prayer hour: a
            // recording, or a text the host voice reads word for word. Each
            // airing takes the oldest one waiting.
            <<<'SQL'
            CREATE TABLE opening_prayers (
              id INTEGER PRIMARY KEY,
              program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
              mode TEXT NOT NULL CHECK (mode IN ('text', 'audio')),
              name TEXT NOT NULL DEFAULT '',
              text_en TEXT NOT NULL DEFAULT '',
              text_de TEXT NOT NULL DEFAULT '',
              audio TEXT,
              audio_ms INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting', 'aired')),
              aired_at INTEGER,
              created_by TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL
            );
            CREATE INDEX opening_prayers_waiting ON opening_prayers(program_id, status, id);
            SQL,
            // 8 — preachings in the library (kind `preaching`: a sermon on
            // YouTube, which a preaching program plays like a song). The CHECK
            // is rebuilt as in 5.
            <<<'SQL'
            CREATE TABLE library_items_new (
              id INTEGER PRIMARY KEY,
              kind TEXT NOT NULL DEFAULT 'song' CHECK (kind IN ('song', 'jingle', 'contrib', 'bed', 'preaching')),
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
            INSERT INTO library_items_new (id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated)
            SELECT id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated
            FROM library_items;
            DROP TABLE library_items;
            ALTER TABLE library_items_new RENAME TO library_items;
            CREATE UNIQUE INDEX library_yt ON library_items(yt_id) WHERE yt_id IS NOT NULL;
            CREATE INDEX library_kind_active ON library_items(kind, active);
            SQL,
            // 9 — what the app stores ask of user content and accounts.
            // Listeners report a request on the prayer wall (one report per
            // listener; enough of them take it down until a moderator
            // decides, submissions.hidden = 2). An account deleted by its
            // owner leaves a short note, so a host script being written at
            // that moment can be scrubbed afterwards (Identity\Erasure).
            // IF NOT EXISTS: a test replays older migrations on a current database.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS wall_reports (
              submission_id INTEGER NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
              reporter_id INTEGER NOT NULL REFERENCES identities(id) ON DELETE CASCADE,
              reason TEXT NOT NULL DEFAULT '',
              status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'dismissed', 'actioned')),
              created INTEGER NOT NULL,
              PRIMARY KEY (submission_id, reporter_id)
            ) WITHOUT ROWID;
            CREATE INDEX IF NOT EXISTS wall_reports_open ON wall_reports(status, submission_id);
            CREATE TABLE IF NOT EXISTS erasures (
              id INTEGER PRIMARY KEY,
              subs TEXT NOT NULL DEFAULT '[]',
              submission_ids TEXT NOT NULL DEFAULT '[]',
              created INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS erasures_created ON erasures(created);
            SQL,
            // 10 — the prayer hour of listeners' prayers: its collection is N
            // songs (0–3) and then prayer music, no longer one or the other.
            // Every program carries the block (cleanSettings wrote it into
            // music programs too, as "music, 2 songs": switched to a prayer
            // hour later they must not play two songs). Only the old default
            // of 8 minutes becomes the new 10. A prayer hour now also takes
            // listeners' prayers (`intercession`). A new plan version: drafts
            // made by the old order are planned again.
            <<<'SQL'
            UPDATE programs SET settings = json_set(settings, '$.prayer.collect.songs',
                CASE WHEN json_extract(settings, '$.prayer.collect.with') = 'songs'
                     THEN MIN(3, MAX(0, COALESCE(json_extract(settings, '$.prayer.collect.songs'), 0))) ELSE 0 END)
              WHERE json_valid(settings) AND json_type(settings, '$.prayer.collect') = 'object';
            UPDATE programs SET settings = json_remove(settings, '$.prayer.collect.with')
              WHERE json_valid(settings) AND json_type(settings, '$.prayer.collect.with') IS NOT NULL;
            UPDATE programs SET settings = json_set(settings, '$.prayer.collect.minutes', 10)
              WHERE json_valid(settings) AND json_extract(settings, '$.prayer.collect.minutes') = 8;
            UPDATE programs SET allowed = '["prayer","intercession"]'
              WHERE json_valid(settings) AND json_extract(settings, '$.format') = 'prayer';
            INSERT INTO kv(key, value) VALUES('plan_version', '1')
              ON CONFLICT(key) DO UPDATE SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT);
            SQL,
            // 11 — testimonies, mission videos and films in the library (kinds
            // `testimony`, `mission`, `film`: videos their programs play like
            // a song, as a preaching program its preachings). The CHECK is
            // rebuilt as in 5 and 8. No new plan version: nothing planned
            // changes, and a new one would throw away host breaks already voiced.
            <<<'SQL'
            CREATE TABLE library_items_new (
              id INTEGER PRIMARY KEY,
              kind TEXT NOT NULL DEFAULT 'song' CHECK (kind IN ('song', 'jingle', 'contrib', 'bed', 'preaching', 'testimony', 'mission', 'film')),
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
            INSERT INTO library_items_new (id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated)
            SELECT id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods,
              program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated
            FROM library_items;
            DROP TABLE library_items;
            ALTER TABLE library_items_new RENAME TO library_items;
            CREATE UNIQUE INDEX library_yt ON library_items(yt_id) WHERE yt_id IS NOT NULL;
            CREATE INDEX library_kind_active ON library_items(kind, active);
            SQL,
            // 12 — groups of library items (Library\Groups): a preacher, a
            // church, a ministry, an artist — with a few words and links the
            // stage shows after their items, or blocked at their request. A
            // video belongs to a group by its YouTube channel (yt_channel,
            // filled in for older items by the `channels` job) or because a
            // moderator put it there (group_id).
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS library_groups (
              id INTEGER PRIMARY KEY,
              name TEXT NOT NULL,
              about_en TEXT NOT NULL DEFAULT '',
              about_de TEXT NOT NULL DEFAULT '',
              links TEXT NOT NULL DEFAULT '[]',
              channels TEXT NOT NULL DEFAULT '[]',
              names TEXT NOT NULL DEFAULT '[]',
              notice INTEGER NOT NULL DEFAULT 0,
              blocked INTEGER NOT NULL DEFAULT 0,
              note TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            ALTER TABLE library_items ADD COLUMN group_id INTEGER REFERENCES library_groups(id) ON DELETE SET NULL;
            ALTER TABLE library_items ADD COLUMN yt_channel TEXT;
            CREATE INDEX IF NOT EXISTS library_group ON library_items(group_id) WHERE group_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS library_yt_channel ON library_items(yt_channel) WHERE yt_channel IS NOT NULL;
            SQL,
            // 13 — on-air hosts (Host\Hosts): each with its own persona,
            // voice provider and key, and the lineups of channels and
            // programs (on-air hosts, then fallbacks). Every distinct host the
            // channels had becomes one (same name, picture, voices and style =
            // one host) and that channel's lineup; the channel columns stay,
            // unused, for /mod tabs opened before the deploy. A break's host
            // lives in its context (`host_id`): no ALTER, because tests replay
            // older migrations on a current database and SQLite has no ADD
            // COLUMN IF NOT EXISTS — the seeds are guarded for the same reason.
            // AUTOINCREMENT: a deleted host's id is never handed to a new one
            // (lineups, show picks and break contexts point at it). No new plan
            // version: nothing planned changes, voiced drafts stay.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS hosts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT NOT NULL,
              avatar TEXT,
              color TEXT NOT NULL DEFAULT '#2f7bff',
              about_en TEXT NOT NULL DEFAULT '',
              about_de TEXT NOT NULL DEFAULT '',
              style TEXT NOT NULL DEFAULT '',
              provider TEXT NOT NULL DEFAULT 'openai' CHECK (provider IN ('openai', 'elevenlabs')),
              api_key TEXT NOT NULL DEFAULT '',
              key_hint TEXT NOT NULL DEFAULT '',
              model TEXT NOT NULL DEFAULT '',
              voices TEXT NOT NULL DEFAULT '{}',
              instructions TEXT NOT NULL DEFAULT '',
              settings TEXT NOT NULL DEFAULT '{}',
              max_chars_day INTEGER NOT NULL DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1,
              resting_until INTEGER NOT NULL DEFAULT 0,
              fail_count INTEGER NOT NULL DEFAULT 0,
              last_error TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS host_lineups (
              host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
              channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
              program_id INTEGER REFERENCES programs(id) ON DELETE CASCADE,
              role TEXT NOT NULL DEFAULT 'main' CHECK (role IN ('main', 'fallback')),
              sort INTEGER NOT NULL DEFAULT 0,
              CHECK ((channel_id IS NULL) <> (program_id IS NULL))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS host_lineups_channel ON host_lineups(channel_id, host_id) WHERE channel_id IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS host_lineups_program ON host_lineups(program_id, host_id) WHERE program_id IS NOT NULL;
            CREATE INDEX IF NOT EXISTS host_lineups_host ON host_lineups(host_id);
            INSERT INTO hosts (name, avatar, style, provider, model, voices, instructions, created, updated)
            SELECT host_name, NULLIF(host_avatar, ''), host_style, 'openai', 'gpt-4o-mini-tts',
                   json_object('en', host_voice_en, 'de', host_voice_de),
                   trim('Warm and calm, like a Christian radio host. ' || host_style), MIN(created), MAX(updated)
            FROM channels
            WHERE NOT EXISTS (SELECT 1 FROM hosts)
            GROUP BY host_name, NULLIF(host_avatar, ''), host_voice_en, host_voice_de, host_style
            ORDER BY MAX(is_main) DESC, MIN(sort), MIN(id);
            INSERT INTO host_lineups (host_id, channel_id, role, sort)
            SELECT h.id, c.id, 'main', 0
            FROM channels c
            JOIN hosts h ON h.name = c.host_name AND h.avatar IS NULLIF(c.host_avatar, '') AND h.style = c.host_style
              AND json_extract(h.voices, '$.en') = c.host_voice_en AND json_extract(h.voices, '$.de') = c.host_voice_de
            WHERE NOT EXISTS (SELECT 1 FROM host_lineups);
            SQL,
            // 14 — recorded host lines (Host\Lines): per host, lines written
            // once and recorded once in its voice, that the AI picks for the
            // moments a program takes from the library (`program_lines`, kept
            // out of program settings: cleanSettings() would reset it from an
            // older /mod tab). `part` and the mode `composed` are for breaks
            // built from recorded pieces later — in the CHECKs now, since a
            // CHECK can only change with a rebuilt table. `voice`: who it was
            // recorded with (Lines::signature). Times: created/updated in
            // seconds, last_aired in ms (airtime). No new plan version.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS host_lines (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              host_id INTEGER NOT NULL REFERENCES hosts(id) ON DELETE CASCADE,
              kind TEXT NOT NULL,
              part TEXT NOT NULL DEFAULT 'whole' CHECK (part IN ('whole', 'open', 'close', 'lead')),
              program_id INTEGER REFERENCES programs(id) ON DELETE CASCADE,
              texts TEXT NOT NULL DEFAULT '{}',
              audio TEXT NOT NULL DEFAULT '{}',
              durations TEXT NOT NULL DEFAULT '{}',
              tags TEXT NOT NULL DEFAULT '{}',
              voice TEXT NOT NULL DEFAULT '',
              state TEXT NOT NULL DEFAULT 'recording' CHECK (state IN ('draft', 'recording', 'active', 'paused', 'failed', 'removed')),
              source TEXT NOT NULL DEFAULT 'model' CHECK (source IN ('model', 'moderator')),
              chars INTEGER NOT NULL DEFAULT 0,
              uses INTEGER NOT NULL DEFAULT 0,
              last_aired INTEGER,
              error TEXT NOT NULL DEFAULT '',
              note TEXT NOT NULL DEFAULT '',
              created_by TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS host_lines_pick ON host_lines(host_id, kind, state);
            CREATE INDEX IF NOT EXISTS host_lines_program ON host_lines(program_id) WHERE program_id IS NOT NULL;
            CREATE TABLE IF NOT EXISTS host_line_options (
              host_id INTEGER PRIMARY KEY REFERENCES hosts(id) ON DELETE CASCADE,
              data TEXT NOT NULL DEFAULT '{}',
              updated INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS program_lines (
              program_id INTEGER PRIMARY KEY REFERENCES programs(id) ON DELETE CASCADE,
              mode TEXT NOT NULL DEFAULT 'fresh' CHECK (mode IN ('fresh', 'library', 'composed')),
              kinds TEXT NOT NULL DEFAULT '[]',
              updated INTEGER NOT NULL
            );
            SQL,
            // 15 — voice workers (Host\Workers): Macs of our own that speak
            // for hosts with Qwen3-TTS, pulling voice tasks over HTTPS (they
            // have no public address). `hosts.provider` learns `worker`: a
            // CHECK only changes with a rebuilt table, and dropping `hosts`
            // with foreign keys on would cascade-delete every lineup, line and
            // option — PRAGMA foreign_keys cannot change inside migrate()'s
            // transaction. So the children and the id sequence (AUTOINCREMENT:
            // a deleted host's id never comes back) wait in TEMP tables while
            // the table is rebuilt. Replayed, it simply rebuilds again.
            // `workers.key_mac`: an HMAC of the key, which is shown once.
            // `voice_tasks.text` holds listeners' words: blanked and purged soon.
            <<<'SQL'
            DROP TABLE IF EXISTS hosts_new;
            CREATE TEMP TABLE keep_lineups AS SELECT * FROM host_lineups;
            CREATE TEMP TABLE keep_lines AS SELECT * FROM host_lines;
            CREATE TEMP TABLE keep_options AS SELECT * FROM host_line_options;
            CREATE TEMP TABLE keep_seq AS SELECT seq FROM sqlite_sequence WHERE name = 'hosts';
            CREATE TABLE hosts_new (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT NOT NULL,
              avatar TEXT,
              color TEXT NOT NULL DEFAULT '#2f7bff',
              about_en TEXT NOT NULL DEFAULT '',
              about_de TEXT NOT NULL DEFAULT '',
              style TEXT NOT NULL DEFAULT '',
              provider TEXT NOT NULL DEFAULT 'openai' CHECK (provider IN ('openai', 'elevenlabs', 'worker')),
              api_key TEXT NOT NULL DEFAULT '',
              key_hint TEXT NOT NULL DEFAULT '',
              model TEXT NOT NULL DEFAULT '',
              voices TEXT NOT NULL DEFAULT '{}',
              instructions TEXT NOT NULL DEFAULT '',
              settings TEXT NOT NULL DEFAULT '{}',
              max_chars_day INTEGER NOT NULL DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1,
              resting_until INTEGER NOT NULL DEFAULT 0,
              fail_count INTEGER NOT NULL DEFAULT 0,
              last_error TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            INSERT INTO hosts_new (id, name, avatar, color, about_en, about_de, style, provider, api_key, key_hint, model, voices,
                                   instructions, settings, max_chars_day, active, resting_until, fail_count, last_error, created, updated)
            SELECT id, name, avatar, color, about_en, about_de, style, provider, api_key, key_hint, model, voices,
                   instructions, settings, max_chars_day, active, resting_until, fail_count, last_error, created, updated
            FROM hosts;
            DROP TABLE hosts;
            ALTER TABLE hosts_new RENAME TO hosts;
            INSERT INTO sqlite_sequence (name, seq) SELECT 'hosts', seq FROM temp.keep_seq
              WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'hosts');
            UPDATE sqlite_sequence SET seq = MAX(seq, COALESCE((SELECT seq FROM temp.keep_seq), 0)) WHERE name = 'hosts';
            INSERT INTO host_lineups SELECT * FROM temp.keep_lineups;
            INSERT INTO host_lines SELECT * FROM temp.keep_lines;
            INSERT INTO host_line_options SELECT * FROM temp.keep_options;
            DROP TABLE temp.keep_lineups;
            DROP TABLE temp.keep_lines;
            DROP TABLE temp.keep_options;
            DROP TABLE temp.keep_seq;
            CREATE TABLE IF NOT EXISTS workers (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT NOT NULL,
              key_mac TEXT NOT NULL UNIQUE,
              key_hint TEXT NOT NULL DEFAULT '',
              active INTEGER NOT NULL DEFAULT 1,
              voices TEXT NOT NULL DEFAULT '[]',
              engine TEXT NOT NULL DEFAULT '{}',
              languages TEXT NOT NULL DEFAULT '[]',
              version TEXT NOT NULL DEFAULT '',
              last_seen INTEGER NOT NULL DEFAULT 0,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS voice_tasks (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              purpose TEXT NOT NULL CHECK (purpose IN ('break', 'line', 'try')),
              ref_id INTEGER NOT NULL DEFAULT 0,
              host_id INTEGER NOT NULL,
              lang TEXT NOT NULL,
              text TEXT NOT NULL,
              request TEXT NOT NULL DEFAULT '{}',
              state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'leased', 'done', 'failed', 'cancelled')),
              worker_id INTEGER,
              lease_until INTEGER NOT NULL DEFAULT 0,
              attempts INTEGER NOT NULL DEFAULT 0,
              priority INTEGER NOT NULL DEFAULT 50,
              deadline INTEGER NOT NULL DEFAULT 0,
              audio TEXT NOT NULL DEFAULT '',
              ms INTEGER NOT NULL DEFAULT 0,
              error TEXT NOT NULL DEFAULT '',
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS voice_tasks_queue ON voice_tasks(state, priority, deadline, id);
            CREATE INDEX IF NOT EXISTS voice_tasks_ref ON voice_tasks(purpose, ref_id);
            SQL,
            // 16 — what the station knows about a YouTube video (Library\Knowledge):
            // who and what it really is, found on the web with its sources, and
            // what is sung or said in it, heard by a model — for the check
            // (Christian? biblical? does it fit?) and the host (what it is
            // about, one fact). One row per video, in the library or only
            // requested, so a known song is never looked up twice. No foreign
            // key: a rebuilt `library_items` would empty it (see 15). Public
            // data about the video only, never a listener's name or words.
            // `work` holds what a look-up in progress needs — YouTube's data
            // (YouTube's terms: kept no longer than needed) and the ids of its
            // open calls; `text` only a public-domain one.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS video_knowledge (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              yt_id TEXT NOT NULL UNIQUE,
              kind TEXT NOT NULL DEFAULT 'song',
              state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'working', 'ready', 'failed')),
              work TEXT NOT NULL DEFAULT '{}',
              yt_title TEXT NOT NULL DEFAULT '',
              yt_artist TEXT NOT NULL DEFAULT '',
              title TEXT NOT NULL DEFAULT '',
              artist TEXT NOT NULL DEFAULT '',
              research TEXT NOT NULL DEFAULT '{}',
              analysis TEXT NOT NULL DEFAULT '{}',
              facts_told TEXT NOT NULL DEFAULT '{}',
              text TEXT NOT NULL DEFAULT '',
              text_source TEXT NOT NULL DEFAULT '',
              cost_micros INTEGER NOT NULL DEFAULT 0,
              error TEXT NOT NULL DEFAULT '',
              edited_by TEXT NOT NULL DEFAULT '',
              researched INTEGER NOT NULL DEFAULT 0,
              created INTEGER NOT NULL,
              updated INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS video_knowledge_state ON video_knowledge(state, updated);
            SQL,
        ];
    }
}
