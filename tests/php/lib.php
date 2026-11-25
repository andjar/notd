<?php
// A small test library: no dependencies, readable in one sitting.
//
//   test('name', function () { eq(2, 1 + 1); });
//   cases('adds', [[1, 1, 2], [2, 3, 5]], function ($a, $b, $sum) { eq($sum, $a + $b); });
//
// Assertions throw TestFailure; the runner catches it and reports the file and line.

declare(strict_types=1);

final class TestFailure extends Exception {}

final class TestRegistry
{
    /** @var array<int, array{name: string, fn: callable}> */
    public static array $tests = [];
}

function test(string $name, callable $fn): void
{
    TestRegistry::$tests[] = ['name' => $name, 'fn' => $fn];
}

/** One test per row; a row's values are passed as arguments. String keys name the rows. */
function cases(string $name, array $rows, callable $fn): void
{
    foreach ($rows as $key => $row) {
        $label = is_string($key) ? $key : '#' . $key;
        $args = is_array($row) ? array_values($row) : [$row];
        test("$name [$label]", fn() => $fn(...$args));
    }
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

function eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? "$message: " : '') . 'expected ' . show($expected) . ', got ' . show($actual));
    }
}

function ok(mixed $condition, string $message = 'expected a true value'): void
{
    if ($condition !== true) {
        fail($message . ' (got ' . show($condition) . ')');
    }
}

/** Runs $fn and returns the exception it throws; fails if it throws nothing or the wrong class. */
function throws(callable $fn, string $class = Throwable::class, ?string $messagePart = null): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof TestFailure && $class !== TestFailure::class) {
            throw $e; // a failed assertion inside $fn is a failure, not the expected exception
        }
        if (!$e instanceof $class) {
            fail("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($messagePart !== null && !str_contains($e->getMessage(), $messagePart)) {
            fail("expected a message containing " . show($messagePart) . ', got ' . show($e->getMessage()));
        }
        return $e;
    }
    fail("expected $class to be thrown");
}

function show(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    return $json === false ? var_export($value, true) : $json;
}
