<?php
declare(strict_types=1);

namespace Arche;

/**
 * The minimum that lets a fresh install run: one main channel, one program
 * that accepts everything, and a 24 h plan for every weekday. Content (songs)
 * is deliberately not seeded — moderators curate the pool in /mod, the same
 * way they will in production.
 */
final class Seeder
{
    public static function ensureDefaults(Store $store, int $nowMs): void
    {
        $t = intdiv($nowMs, 1000);
        if ((int) $store->value('SELECT COUNT(*) FROM channels') > 0) {
            self::ensureHost($store, $t);
            return;
        }

        $channelId = $store->insert('channels', [
            'slug' => 'main',
            'name_en' => 'ARCHE',
            'name_de' => 'ARCHE',
            'is_main' => 1,
            'timezone' => 'Europe/Berlin',
            'color' => '#2f7bff',
            'sort' => 0,
            'created' => $t,
            'updated' => $t,
        ]);
        $programId = $store->insert('programs', [
            'channel_id' => $channelId,
            'slug' => 'live',
            'title_en' => 'ARCHE Live',
            'title_de' => 'ARCHE Live',
            'subtitle_en' => 'Worship music, prayer and stories from around the world',
            'subtitle_de' => 'Lobpreis, Gebet und Geschichten aus aller Welt',
            'description_en' => 'The everyday program: worship music with your requests, prayers and testimonies.',
            'description_de' => 'Das Alltagsprogramm: Lobpreis mit euren Wünschen, Gebeten und Zeugnissen.',
            'tagline_en' => 'One program. Many nations. One family.',
            'tagline_de' => 'Ein Programm. Viele Nationen. Eine Familie.',
            'color' => '#2f7bff',
            'stage_mode' => 'flyins',
            'allowed' => '["song","story","testimony","greeting","prayer"]',
            'themes' => '["worship"]',
            'moods' => '[]',
            'settings' => '{}',
            'created' => $t,
            'updated' => $t,
        ]);
        $planId = $store->insert('day_plans', [
            'channel_id' => $channelId,
            'name' => 'Standard',
            'created' => $t,
            'updated' => $t,
        ]);
        $store->insert('day_plan_blocks', [
            'day_plan_id' => $planId,
            'start_min' => 0,
            'end_min' => 1440,
            'program_id' => $programId,
        ]);
        for ($d = 1; $d <= 7; $d++) {
            $store->insert('week_plan', ['channel_id' => $channelId, 'weekday' => $d, 'day_plan_id' => $planId]);
        }
        $store->update('channels', [
            'default_day_plan_id' => $planId,
            'fallback_program_id' => $programId,
        ], 'id = ?', [$channelId]);
        $store->audit('system', 'Seeded defaults', 'channel main, program live, plan Standard');
        self::ensureHost($store, $t);
    }

    /**
     * Hope, the station's first host (OpenAI, coral), on the main channel's
     * lineup — when there is no host at all: a fresh install (migration 13
     * runs before the channel exists, so it has none to copy). Not while a
     * test replays migrations up to one before the hosts table.
     */
    private static function ensureHost(Store $store, int $t): void
    {
        if ($store->value("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'hosts'") === null) return;
        if ((int) $store->value('SELECT COUNT(*) FROM hosts') > 0) return;
        $channel = $store->value('SELECT id FROM channels ORDER BY is_main DESC, active DESC, sort, id LIMIT 1');
        $hostId = $store->insert('hosts', [
            'name' => 'Hope',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-tts',
            'voices' => '{"en":"coral","de":"coral"}',
            'instructions' => 'Warm and calm, like a Christian radio host.',
            'created' => $t,
            'updated' => $t,
        ]);
        if ($channel !== null) $store->insert('host_lineups', ['host_id' => $hostId, 'channel_id' => (int) $channel, 'role' => 'main', 'sort' => 0]);
    }
}
