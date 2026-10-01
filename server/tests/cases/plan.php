<?php
declare(strict_types=1);

use Arche\Plan\Easter;

test('plan: Easter Sunday dates', function () {
    eq(Easter::sunday(2024), '2024-03-31', 'Easter 2024');
    eq(Easter::sunday(2026), '2026-04-05', 'Easter 2026');
    eq(Easter::sunday(2027), '2027-03-28', 'Easter 2027');
    eq(Easter::offset(2026, -2), '2026-04-03', 'Good Friday 2026');
    eq(Easter::offset(2026, 49), '2026-05-24', 'Pentecost 2026');
});

test('plan: default seed covers the whole day with one program', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $blocks = $app->resolver()->blocksForDate($ch, '2026-09-23');
    eq(count($blocks), 1, 'one block');
    eq($blocks[0]['end'] - $blocks[0]['start'], 86_400_000, '24 h');
    eq($blocks[0]['start'], strtotime('2026-09-22T22:00:00Z') * 1000, 'starts at Berlin midnight');
});

test('plan: DST days are 23 and 25 hours long', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $spring = $app->resolver()->blocksForDate($ch, '2026-03-29');
    $autumn = $app->resolver()->blocksForDate($ch, '2026-10-25');
    eq(end($spring)['end'] - $spring[0]['start'], 23 * 3_600_000, 'spring day');
    eq(end($autumn)['end'] - $autumn[0]['start'], 25 * 3_600_000, 'autumn day');
});

test('plan: special days beat the week plan, gaps use the fallback program', function () {
    $app = TestKit::app();
    $cat = $app->catalog();
    $ch = TestKit::main($app);
    $cid = (int) $ch['id'];
    $live = (int) $ch['fallback_program_id'];
    $prayer = $cat->saveProgram(null, $cid, ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde', 'allowed' => ['prayer']], 'test');
    $christmas = $cat->saveDayPlan(null, $cid, 'Christmas', [['start_min' => 7 * 60, 'end_min' => 8 * 60, 'program_id' => $prayer['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Christmas Eve', 'kind' => 'date', 'month' => 12, 'day' => 24, 'day_plan_id' => $christmas], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Good Friday', 'kind' => 'easter', 'easter_offset' => -2, 'day_plan_id' => $christmas], 'test');
    $ch = TestKit::main($app);

    $blocks = $app->resolver()->blocksForDate($ch, '2026-12-24');
    eq(count($blocks), 3, 'fallback, prayer, fallback');
    eq($blocks[0]['program_id'], $live, 'gap before uses the fallback');
    eq($blocks[1]['program_id'], (int) $prayer['id'], 'prayer block');
    eq($blocks[1]['start'], strtotime('2026-12-24T06:00:00Z') * 1000, '07:00 CET');
    eq(count($app->resolver()->blocksForDate($ch, '2026-04-03')), 3, 'Good Friday via Easter offset');
    eq(count($app->resolver()->blocksForDate($ch, '2026-04-04')), 1, 'Holy Saturday is a normal day');
    check(refuses(fn() => $cat->saveDayPlan(null, $cid, 'Bad', [
        ['start_min' => 60, 'end_min' => 120, 'program_id' => $prayer['id']],
        ['start_min' => 90, 'end_min' => 150, 'program_id' => $prayer['id']],
    ], 'test'), 'overlapping_blocks'), 'overlapping blocks are refused');
});

test('plan: a program running across midnight is one block', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $b = $app->resolver()->blockAt($ch, strtotime('2026-09-23T21:30:00Z') * 1000);
    check($b['end'] > strtotime('2026-09-23T22:00:00Z') * 1000, 'extends past local midnight');
});

test('plan: a program\'s run goes on across midnight both ways, where its block does not', function () {
    $app = TestKit::app();
    $cat = $app->catalog();
    $ch = TestKit::main($app);
    $cid = (int) $ch['id'];
    $p = $cat->saveProgram(null, $cid, ['slug' => 'vigil', 'title_en' => 'Vigil', 'title_de' => 'Nachtwache'], 'test');
    // 22:00–24:00 on the 23rd and 00:00–01:30 on the 24th, Berlin (20:00 and 23:30 UTC).
    $today = $cat->saveDayPlan(null, $cid, 'Late', [['start_min' => 1320, 'end_min' => 1440, 'program_id' => $p['id']]], 'test');
    $tomorrow = $cat->saveDayPlan(null, $cid, 'Early', [['start_min' => 0, 'end_min' => 90, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Late', 'kind' => 'date', 'month' => 9, 'day' => 23, 'day_plan_id' => $today], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Early', 'kind' => 'date', 'month' => 9, 'day' => 24, 'day_plan_id' => $tomorrow], 'test');
    $start = strtotime('2026-09-23T20:00:00Z') * 1000;
    $end = strtotime('2026-09-23T23:30:00Z') * 1000;
    $r = $app->resolver();
    foreach ([$start, $start + 60_000, $end - 100 * 60_000, $end - 60_000] as $t) {
        eq($r->runAt($ch, $t), ['start' => $start, 'end' => $end, 'program_id' => (int) $p['id']], 'one run at ' . gmdate('H:i', intdiv($t, 1000)));
    }
    eq($r->blockAt($ch, $start + 60_000)['end'], strtotime('2026-09-23T22:00:00Z') * 1000, 'its block, two hours before midnight, still ends there');
    eq($r->blockAt($ch, $end - 60_000)['start'], strtotime('2026-09-23T22:00:00Z') * 1000, 'and after midnight a block starts anew');
});
