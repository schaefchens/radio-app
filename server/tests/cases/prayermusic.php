<?php
declare(strict_types=1);

use Arche\Http\Kernel;
use Arche\Http\Request;

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
    TestKit::songs($app, 3);
    $lib = $app->library();
    $before = $lib->fingerprint();
    $bed = $lib->addBed(silentMp3(300), 'Worship pad', 'test');
    eq([$bed['kind'], $bed['title']], ['bed', 'Worship pad'], 'stored as background music');
    check(abs($bed['duration_ms'] - 300_000) < 3000, 'with its length');
    check(str_starts_with((string) $bed['audio'], '/media/beds/') && is_file((string) $app->media()->path((string) $bed['audio'])), 'published under /media/beds');
    eq(array_column($lib->beds(), 'id'), [$bed['id']], 'offered to the program settings');
    eq($lib->fingerprint(), $before, 'not a song: the music selection does not change');
    eq($app->selector()->jingle(600_000), null, 'and it is never played as a jingle');
    check(refuses(fn() => $lib->addBed(silentMp3(10), 'Too short', 'test'), 'invalid_bed'), 'shorter than 20 s is refused');
    check(refuses(fn() => $lib->addBed(silentMp3(620), 'Too long', 'test'), 'invalid_bed'), 'longer than 10 min is refused');
    $notMp3 = tempnam(sys_get_temp_dir(), 'bed');
    file_put_contents($notMp3, str_repeat('not audio ', 100));
    check(refuses(fn() => $lib->addBed($notMp3, 'Text', 'test'), 'invalid_bed'), 'a file that is not an MP3 is refused');
    $lib->update((int) $bed['id'], ['active' => false], 'test');
    eq($lib->beds(), [], 'switched off, it is no longer offered');
});

test('prayer music: moderators upload it in /mod, listeners cannot', function () {
    $app = TestKit::app();
    $upload = function (array $headers) use ($app): array {
        $file = silentMp3(30);
        $req = new Request('POST', '/api/mod/beds', [], $headers, '', ['title' => 'Quiet strings'], ['audio' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK]]);
        $res = (new Kernel($app))->handle($req);
        return [$res->status, $res->data];
    };
    eq($upload(authHeaders())[0], 403, 'not for listeners');
    $h = modHeaders($app);
    [$st, $data] = $upload($h);
    eq([$st, $data['item']['kind'] ?? null, $data['item']['title'] ?? null], [200, 'bed', 'Quiet strings'], 'a moderator adds it');
    [$st, $data] = modGet($app, '/api/mod/library', ['kind' => 'bed'], $h);
    eq([$st, count($data['items'])], [200, 1], 'and finds it in the library');
});
