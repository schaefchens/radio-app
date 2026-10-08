<?php
declare(strict_types=1);

use Arche\Support\HttpResponse;

/**
 * The library's housekeeping in /mod: what is likely the same song or video
 * as one about to be added — another upload of a film came in beside the
 * one there (2026-10-08) —, and deleting a switched-off item for good.
 */

/** A library item as /mod or a request leaves it. @param array<string,mixed> $more */
function libItem(Arche\App $app, string $kind, string $yt, string $title, string $artist, int $seconds, array $more = []): int
{
    return $app->store()->insert('library_items', $more + ['kind' => $kind, 'yt_id' => $yt, 'title' => $title, 'artist' => $artist,
        'duration_ms' => $seconds * 1000, 'created' => 1, 'updated' => 1]);
}

/** What a song or video was looked up to be (Library\Knowledge), with the original's title and its writers as research gives them. */
function libKnown(Arche\App $app, string $yt, string $title, string $original): void
{
    $app->store()->insert('video_knowledge', ['yt_id' => $yt, 'kind' => 'film', 'state' => 'ready', 'title' => $title, 'artist' => 'Pure Flix',
        'research' => (string) json_encode(['identity' => ['identified' => true, 'original' => $original]]), 'created' => 1, 'updated' => 1]);
}

/** YouTube's answer for one video. */
function libYouTube(string $id, string $title, string $channel, int $seconds): HttpResponse
{
    return new HttpResponse(200, (string) json_encode(['items' => [[
        'id' => $id,
        'snippet' => ['title' => $title, 'channelTitle' => $channel, 'liveBroadcastContent' => 'none', 'tags' => [], 'description' => ''],
        'contentDetails' => ['duration' => sprintf('PT%dH%dM%dS', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
    ]]]));
}

test('library: before adding, a moderator sees what is likely the same song or video — another upload of a film or a song — and adds it only on purpose; sequels, two films sharing a word and songs sharing one are none', function () {
    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $h = modHeaders($app);
    // As in the station's library on 2026-10-08.
    $film = libItem($app, 'film', 'HimmlKino01', 'Vergeben - Forgiven - 2016', 'Himmlisches Kino', 4815);
    libItem($app, 'film', 'GottTot2014', 'Gott ist nicht tot (2014)', 'Himmlisches Kino', 6495);
    libItem($app, 'film', 'AmishGrace1', 'Amish Grace (2010)', 'DiesDas', 5116);
    $song = libItem($app, 'song', 'BenFuller01', 'If I Got Jesus', 'Ben Fuller', 237);
    $look = function (string $id, string $title, string $channel, int $seconds, string $kind) use ($app, $http, $h): array {
        $http->answers[] = libYouTube($id, $title, $channel, $seconds);
        return array_column(call($app, 'POST', '/api/mod/library/lookup', ['url' => "https://youtu.be/$id", 'kind' => $kind], $h)[1]['video']['duplicates'] ?? ['?'], 'id');
    };
    eq($look('DzangoFilm1', 'Vergeben - Forgiven mit KEVIN SORBO', 'Dzango - Filme für Männer', 4815, 'film'), [$film], 'another upload of the film');
    eq($look('LyricVideo1', 'Ben Fuller - If I Got Jesus (Official Lyric Video)', 'Ben Fuller', 239, 'song'), [$song], 'another upload of a song');
    eq($look('GottTot2016', 'Gott ist nicht tot 2 (2016)', 'Himmlisches Kino', 7226, 'film'), [], 'a sequel is another film');
    eq($look('GottTot3III', 'Gott ist nicht tot III', 'Netzkino', 6357, 'film'), [], 'also numbered the Roman way');
    eq($look('SavedGrace1', 'Saved by Grace', 'SkipStone Studios', 5083, 'film'), [], 'two films sharing a word and nearly a length');
    eq($look('FriendJesus', 'What a Friend We Have in Jesus | Acoustic Worship', 'Grace Worship', 251, 'song'), [], 'two songs sharing a word');
    eq($look('ForgivenSng', 'Forgiven', 'Sanctus Real', 4815, 'song'), [], 'a song is never a film of its name');

    $add = function (array $more) use ($app, $http, $h): array {
        $http->answers[] = libYouTube('DzangoFilm1', 'Vergeben - Forgiven mit KEVIN SORBO', 'Dzango - Filme für Männer', 4815);
        return call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/DzangoFilm1', 'kind' => 'film'] + $more, $h);
    };
    [$status, $data] = $add([]);
    eq([$status, $data['error'] ?? null, array_column($data['duplicates'] ?? [], 'id')], [409, 'possible_duplicate', [$film]], 'not added without being asked');
    check($app->library()->byYouTube('DzangoFilm1') === null, 'nothing added');
    eq($add(['allow_duplicate' => true])[1]['item']['yt_id'] ?? null, 'DzangoFilm1', 'added on purpose');

    $dupe = (int) $app->library()->byYouTube('DzangoFilm1')['id'];
    $listed = array_column(modGet($app, '/api/mod/library', [], $h)[1]['items'], 'duplicates', 'id');
    eq([array_column($listed[$film], 'id'), array_column($listed[$dupe], 'id'), $listed[$song]], [[$dupe], [$film], []], "/mod's list names the other of a pair");
    $only = array_column(modGet($app, '/api/mod/library', ['dupes' => '1'], $h)[1]['items'], 'id');
    sort($only);
    eq($only, [$film, $dupe], 'and shows only pairs when asked');
});

test('library: what a film was looked up to be counts as its name — the original\'s title, never the writers it shares with another film', function () {
    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $h = modHeaders($app);
    $kixi = libItem($app, 'film', 'KixiFilm001', 'Ganzer Film auf Deutsch - Lee Majors - Cybill Shepherd', 'Kixi - Familienfilme', 6887);
    libKnown($app, 'KixiFilm001', 'Woran glaubst du?', 'Do You Believe?; Drehbuch von Chuck Konzelman und Cary Solomon.');
    libItem($app, 'film', 'GodNotDead1', 'Gott ist nicht tot (2014)', 'Himmlisches Kino', 6495);
    libKnown($app, 'GodNotDead1', 'Gott ist nicht tot', 'God’s Not Dead; Drehbuch von Cary Solomon und Chuck Konzelman.');
    $http->answers[] = libYouTube('WoranGlaubt', 'Woran glaubst du? - Kompletter Film', 'Netzkino', 6887);
    eq(array_column(call($app, 'POST', '/api/mod/library/lookup', ['url' => 'https://youtu.be/WoranGlaubt', 'kind' => 'film'], $h)[1]['video']['duplicates'] ?? [], 'id'),
        [$kixi], 'found by the title it was looked up to have');
    eq($app->library()->duplicatePairs(), [], 'two films by the same writers are no pair');
});

test('library: a switched-off song or video is deleted for good — what is planned with it goes, nothing points at its id any more, its reactions and errors go; an active one and prayer music are not deleted; moderators only', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    for ($i = 0; $i < 8; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $h = modHeaders($app);
    $store = $app->store();
    $target = TestKit::songs($app, 1)[0];
    // Something of it aired, something is planned, a listener asked for it, listeners reacted, a player failed.
    $aired = (int) $store->value("SELECT id FROM timeline_items WHERE type = 'song' AND state = 'committed' ORDER BY seq LIMIT 1");
    $planned = (int) $store->value("SELECT id FROM timeline_items WHERE type = 'song' AND state = 'draft' AND unit IS NULL ORDER BY seq DESC LIMIT 1");
    check($aired > 0 && $planned > 0, 'a program to work with');
    $store->query('UPDATE timeline_items SET library_id = ? WHERE id IN (?, ?)', [$target, $aired, $planned]);
    $who = (int) $store->value('SELECT id FROM identities ORDER BY id LIMIT 1');
    $sub = $store->insert('submissions', ['public_id' => 'deltest00001', 'identity_id' => $who, 'channel_id' => (int) TestKit::main($app)['id'], 'program_id' => 1,
        'type' => 'song', 'status' => 'aired', 'yt_id' => 'whatever001', 'library_id' => $target, 'created' => 1, 'updated' => 1]);
    $store->query("INSERT INTO reactions(library_id, day, kind, n) VALUES(?, '2026-10-08', 'heart', 3)", [$target]);
    $store->query('INSERT INTO playback_errors(library_id, reporter, code, time) VALUES(?, ?, 150, 1)', [$target, 'r1']);
    $bed = $store->insert('library_items', ['kind' => 'bed', 'title' => 'Stille', 'artist' => '', 'audio' => '/media/beds/x.mp3', 'duration_ms' => 60_000, 'active' => 0, 'created' => 1, 'updated' => 1]);

    eq(call($app, 'DELETE', "/api/mod/library/$target", [], $h), [409, ['error' => 'still_active']], 'switched off first');
    eq(call($app, 'DELETE', "/api/mod/library/$bed", [], $h)[1]['error'] ?? null, 'not_deletable', 'prayer music is not deleted here');
    $app->library()->update($target, ['active' => false], 'mod:test');
    eq(call($app, 'DELETE', "/api/mod/library/$target", [], authHeaders())[0], 403, 'a listener cannot');
    eq(call($app, 'DELETE', "/api/mod/library/$target", [], $h), [200, ['deleted' => $target]], 'deleted');

    check($app->library()->get($target) === null, 'gone from the library');
    eq([$store->value('SELECT state FROM timeline_items WHERE id = ?', [$planned]), $store->value('SELECT state FROM timeline_items WHERE id = ?', [$aired])],
        ['dropped', 'committed'], 'its planned airing dropped, what aired kept');
    check(str_contains((string) $store->value('SELECT payload FROM timeline_items WHERE id = ?', [$aired]), '"title"'), 'with its own title and link');
    eq([(int) $store->value('SELECT COUNT(*) FROM timeline_items WHERE library_id = ?', [$target]), $store->value('SELECT library_id FROM submissions WHERE id = ?', [$sub]),
        (int) $store->value('SELECT COUNT(*) FROM reactions WHERE library_id = ?', [$target]), (int) $store->value('SELECT COUNT(*) FROM playback_errors WHERE library_id = ?', [$target])],
        [0, null, 0, 0], 'nothing points at its id: a song added next may get it');
    check((int) $store->value("SELECT COUNT(*) FROM audit WHERE event = 'Library delete'") === 1, 'in the audit');
    eq(call($app, 'DELETE', "/api/mod/library/$target", [], $h)[0], 404, 'once');
});
