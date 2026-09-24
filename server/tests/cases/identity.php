<?php
declare(strict_types=1);

function device(): array
{
    $h = bin2hex(random_bytes(16));
    $id = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    return [$id, bin2hex(random_bytes(32))];
}

function credential(): array
{
    $h = bin2hex(random_bytes(16));
    $id = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-8' . substr($h, 13, 3) . '-9' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    return [$id, bin2hex(random_bytes(32))];
}

test('identity: rows are created lazily and the device secret is enforced', function () {
    $app = TestKit::app();
    $ids = $app->identities();
    [$d, $s] = device();
    eq($ids->resolve($d, $s, false), null, 'unknown device, no row');
    $me = $ids->resolve($d, $s, true);
    check($me !== null && strlen($me['public_id']) === 10, 'created');
    eq($ids->resolve($d, $s, false)['id'], $me['id'], 'same identity again');
    check(refuses(fn() => $ids->resolve($d, bin2hex(random_bytes(32)), false), 'bad_identity'), 'wrong secret refused');
    check(!str_contains(json_encode($app->store()->all('SELECT * FROM identities')), $d), 'device id never stored in clear');
});

test('identity: claim, then login from a second device aliases it', function () {
    $app = TestKit::app();
    $ids = $app->identities();
    [$d1, $s1] = device();
    [$d2, $s2] = device();
    [$cid, $cs] = credential();
    $a = $ids->resolve($d1, $s1, true);
    $a = $ids->claim($a, $cid, $cs);
    check(!empty($a['cred_key']), 'claimed');
    check(refuses(fn() => $ids->claim($a, $cid, $cs), 'already_claimed'), 'second claim refused');
    $b = $ids->resolve($d2, $s2, true);
    check($b['id'] !== $a['id'], 'second device starts separate');
    $logged = $ids->login($d2, $s2, $cid, $cs);
    eq($logged['id'], $a['id'], 'login returns the passphrase identity');
    eq($ids->resolve($d2, $s2, false)['id'], $a['id'], 'second device now resolves to it');
    check(refuses(fn() => $ids->login($d2, $s2, $cid, bin2hex(random_bytes(32))), 'unknown_passphrase'), 'wrong secret refused');
});

test('identity: roles need a passphrase; setup claims the first admin once', function () {
    $app = TestKit::app();
    $ids = $app->identities();
    [$d, $s] = device();
    $me = $ids->resolve($d, $s, true);
    check(refuses(fn() => $ids->setRole($me['public_id'], 'moderator', 't'), 'needs_passphrase'), 'anonymous cannot get a role');
    [$cid, $cs] = credential();
    $ids->claim($me, $cid, $cs);
    eq($ids->setRole($me['public_id'], 'moderator', 't')['role'], 'moderator', 'claimed identity can');
});

test('identity: unclaimed, inactive, unreferenced devices are purged', function () {
    $app = TestKit::app();
    $ids = $app->identities();
    [$d, $s] = device();
    $ids->resolve($d, $s, true);
    TestKit::clock($app)->advance(61 * 86_400_000);
    eq($ids->purgeInactive(), 1, 'purged');
});
