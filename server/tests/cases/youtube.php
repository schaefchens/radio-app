<?php
declare(strict_types=1);

use Arche\Library\YouTube;

test('youtube: ids from every usual URL form', function () {
    foreach (['https://www.youtube.com/watch?v=qlsQrycKKsY', 'https://youtu.be/qlsQrycKKsY?t=3', 'https://www.youtube.com/watch?feature=share&v=qlsQrycKKsY',
        'https://www.youtube-nocookie.com/embed/qlsQrycKKsY', 'https://youtube.com/shorts/qlsQrycKKsY', 'qlsQrycKKsY'] as $u) {
        eq(YouTube::parseId($u), 'qlsQrycKKsY', $u);
    }
    eq(YouTube::parseId('https://example.com/watch?v=qlsQrycKKsY'), null, 'other hosts');
});

test('youtube: durations and title splitting, either way round', function () {
    eq(YouTube::isoDurationMs('PT4M58S'), 298_000, 'PT4M58S');
    eq(YouTube::isoDurationMs('PT1H2M'), 3_720_000, 'PT1H2M');
    eq(YouTube::splitTitle('Chris Tomlin - Good Good Father (Official Music Video)', 'Chris Tomlin'), ['Chris Tomlin', 'Good Good Father'], 'artist first');
    eq(YouTube::splitTitle('What A Beautiful Name - Hillsong Worship', 'Hillsong Worship'), ['Hillsong Worship', 'What A Beautiful Name'], 'title first, artist = channel');
    eq(YouTube::splitTitle('Graves Into Gardens ft. Brandon Lake | Live | Elevation Worship', 'Elevation Worship'), ['Elevation Worship', 'Graves Into Gardens ft. Brandon Lake'], 'pipes');
    eq(YouTube::splitTitle('Ewigkeit', 'Outbreakband'), ['Outbreakband', 'Ewigkeit'], 'no separator');
});

test('youtube: a thumbnail for an id that starts with - or _; no dotfile in /media', function () {
    $app = TestKit::app(['THUMBS' => '1']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $img = imagecreatetruecolor(480, 360);
    ob_start();
    imagejpeg($img);
    $jpg = (string) ob_get_clean();
    foreach (['-2pVBu7AYhw', '_Xq3m8JvCko'] as $id) {
        $http->answers[] = new Arche\Support\HttpResponse(200, $jpg);
        $url = $app->media()->cacheThumb($id);
        eq($url, "/media/thumbs/$id.jpg", $id);
        check(is_file((string) $app->media()->path((string) $url)), "$id cached");
    }
    foreach (['.htaccess', '.user.ini', 'x.php.jpg'] as $name) {
        check(refuses(fn() => $app->media()->put('thumbs', $name, 'x')), "$name refused");
    }
});
