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
        if ((int) $store->value('SELECT COUNT(*) FROM channels') > 0) return;
        $t = intdiv($nowMs, 1000);

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
    }
}
