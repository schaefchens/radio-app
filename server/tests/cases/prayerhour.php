<?php
declare(strict_types=1);

/**
 * An MP3 of silence, $seconds long: MPEG-1 Layer III frames (128 kbit/s,
 * 44.1 kHz, 1152 samples each) are all getID3 needs for the duration.
 */
function silentMp3(float $seconds): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'bed');
    file_put_contents($tmp, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\0", 413), (int) ceil($seconds * 44100 / 1152)));
    return $tmp;
}

test('prayer music: an MP3 of 20 s to 10 min becomes background music; anything else is refused', function () {
    $app = TestKit::app();
    $lib = $app->library();
    $bed = $lib->addBed(silentMp3(300), 'Worship pad', 'test');
    eq($bed['kind'], 'bed', 'stored as background music');
    check(abs($bed['duration_ms'] - 300_000) < 3000, 'with its length');
    check(str_starts_with((string) $bed['audio'], '/media/beds/') && is_file((string) $app->media()->path((string) $bed['audio'])), 'published under /media/beds');
    eq(array_column($lib->beds(), 'id'), [$bed['id']], 'offered to the program settings');
    eq($lib->fingerprint(), '0:0', 'not a song: the music selection does not change');
    check(refuses(fn() => $lib->addBed(silentMp3(10), 'Too short', 'test'), 'invalid_bed'), 'shorter than 20 s is refused');
    check(refuses(fn() => $lib->addBed(silentMp3(620), 'Too long', 'test'), 'invalid_bed'), 'longer than 10 min is refused');
    $notMp3 = tempnam(sys_get_temp_dir(), 'bed');
    file_put_contents($notMp3, str_repeat('not audio ', 100));
    check(refuses(fn() => $lib->addBed($notMp3, 'Text', 'test'), 'invalid_bed'), 'a file that is not an MP3 is refused');
});
