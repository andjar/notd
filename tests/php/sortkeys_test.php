<?php
require_once __DIR__ . '/support/server.php';
require_once dirname(__DIR__, 2) . '/server/sortkeys.php';

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/sort_keys.json'), true);

test('between gives exactly the keys the JavaScript version gives', function () use ($fixture) {
    $bad = [];
    foreach ($fixture['between'] as [$a, $b, $k]) {
        $got = SortKeys::between($a, $b);
        if ($got !== $k) {
            $bad[] = "between($a, $b): js $k, php $got";
        }
    }
    eq([], array_slice($bad, 0, 5), count($bad) . ' mismatches');
    ok(count($fixture['between']) > 2000, 'enough cases');
});

test('nBetween gives the same keys as keysBetween', function () use ($fixture) {
    foreach ($fixture['many'] as [$a, $b, $n, $keys]) {
        eq($keys, SortKeys::nBetween($a, $b, $n));
    }
});

cases('invalid keys are refused', ['', 'a', 'a00', '!', 'A00000000000000000000000000', 5, null], function ($key) {
    eq(false, SortKeys::isValid($key));
});

test('between refuses reversed or equal bounds and invalid keys', function () {
    throws(fn() => SortKeys::between('a1', 'a0'), InvalidArgumentException::class);
    throws(fn() => SortKeys::between('a1', 'a1'), InvalidArgumentException::class);
    throws(fn() => SortKeys::between('bad!', null), InvalidArgumentException::class);
});

test('PHP and JavaScript ids have the same form', function () {
    $out = shell_exec('node --input-type=module -e ' . escapeshellarg(
        "import { newId } from '" . dirname(__DIR__, 2) . "/client/lib/keys.js'; console.log(newId());"));
    $js = trim((string) $out);
    ok(is_id($js), "js id $js");
    ok(is_id(new_id()));
    ok(strcmp(substr($js, 0, 8), substr(new_id(), 0, 8)) <= 0, 'same clock, same leading digits');
});
