<?php
// Tests for the test library and runner themselves.

test('eq passes on identical values', function () {
    eq([1, 'a' => 'b'], [1, 'a' => 'b']);
});

test('eq is strict about types', function () {
    $e = throws(fn() => eq(1, '1'), TestFailure::class);
    eq('expected 1, got "1"', $e->getMessage());
});

test('eq prefixes its message', function () {
    throws(fn() => eq(1, 2, 'sum'), TestFailure::class, 'sum: expected 1, got 2');
});

test('ok accepts only true', function () {
    ok(true);
    throws(fn() => ok(1), TestFailure::class);
});

test('throws checks the class and message', function () {
    throws(fn() => throw new InvalidArgumentException('bad title'), InvalidArgumentException::class, 'title');
    throws(fn() => throws(fn() => null), TestFailure::class, 'to be thrown');
    throws(fn() => throws(fn() => throw new RuntimeException('x'), LogicException::class), TestFailure::class);
});

cases('cases passes each row as arguments', [
    'small' => [1, 2, 3],
    'large' => [100, 200, 300],
], function (int $a, int $b, int $sum) {
    eq($sum, $a + $b);
});

test('cases names rows and registers one test per row', function () {
    $before = TestRegistry::$tests;
    TestRegistry::$tests = [];
    cases('x', ['one' => [1], 2], fn() => null);
    $names = array_column(TestRegistry::$tests, 'name');
    TestRegistry::$tests = $before;
    eq(['x [one]', 'x [#0]'], $names);
});

test('the runner reports failures and exits non-zero', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run.php')
        . ' ' . escapeshellarg('--dir=' . __DIR__ . '/fixtures/runner') . ' 2>&1';
    exec($cmd, $out, $code);
    $text = implode("\n", $out);
    eq(1, $code, 'exit code');
    ok(str_contains($text, '1 passed, 2 failed in 1 files'), "summary line in:\n$text");
    ok(str_contains($text, 'letters: expected "a", got "b"'), 'assertion message');
    ok(str_contains($text, 'RuntimeException: boom'), 'error message');
    ok(str_contains($text, 'sample_test.php:4'), 'line of the failing assertion');
});

test('the runner filters by name', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run.php')
        . ' ' . escapeshellarg('--dir=' . __DIR__ . '/fixtures/runner') . ' passes 2>&1';
    exec($cmd, $out, $code);
    eq(0, $code);
    ok(str_contains(implode("\n", $out), '1 passed, 0 failed'));
});
