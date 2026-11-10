<?php
/**
 * Fractional order keys and note IDs.
 *
 * PHP port of assets/js/app/fractional-index.js; both must produce identical
 * keys for identical input (tests/ordering_parity.php checks this).
 */

namespace App;

final class Ordering
{
    public const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const SMALLEST_INTEGER = 'A00000000000000000000000000';

    /** A new note ID: 16 lowercase hex characters (matches the client's newId()). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Note IDs are opaque strings; legacy numeric IDs are valid too. */
    public static function isValidId($id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }

    private static function midpoint(string $a, ?string $b): string
    {
        $digits = self::DIGITS;
        if ($b !== null && strcmp($a, $b) >= 0) {
            throw new \InvalidArgumentException("midpoint: $a >= $b");
        }
        if (substr($a, -1) === '0' || ($b !== null && substr($b, -1) === '0')) {
            throw new \InvalidArgumentException('midpoint: trailing zero');
        }
        if ($b !== null) {
            $n = 0;
            while (($a[$n] ?? '0') === ($b[$n] ?? null)) {
                $n++;
            }
            if ($n > 0) {
                return substr($b, 0, $n) . self::midpoint(substr($a, $n), substr($b, $n));
            }
        }
        $digitA = $a !== '' ? strpos($digits, $a[0]) : 0;
        $digitB = $b !== null ? strpos($digits, $b[0]) : strlen($digits);
        if ($digitB - $digitA > 1) {
            // JS Math.round(x.5) rounds up; floor(x + 0.5) matches for non-negative values.
            return $digits[(int)floor(0.5 * ($digitA + $digitB) + 0.5)];
        }
        if ($b !== null && strlen($b) > 1) {
            return substr($b, 0, 1);
        }
        return $digits[$digitA] . self::midpoint(substr($a, 1), null);
    }

    private static function integerLength(string $head): int
    {
        $c = ord($head);
        if ($c >= 97 && $c <= 122) {
            return $c - 97 + 2;
        }
        if ($c >= 65 && $c <= 90) {
            return 90 - $c + 2;
        }
        throw new \InvalidArgumentException("invalid order key head: $head");
    }

    private static function integerPart(string $key): string
    {
        $len = self::integerLength($key[0]);
        if ($len > strlen($key)) {
            throw new \InvalidArgumentException("invalid order key: $key");
        }
        return substr($key, 0, $len);
    }

    public static function isValidKey($key): bool
    {
        if (!is_string($key) || $key === '' || $key === self::SMALLEST_INTEGER) {
            return false;
        }
        if (strspn($key, self::DIGITS) !== strlen($key)) {
            return false;
        }
        try {
            $i = self::integerPart($key);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
        $f = substr($key, strlen($i));
        return substr($f, -1) !== '0';
    }

    private static function incrementInteger(string $x): ?string
    {
        $digits = self::DIGITS;
        $head = $x[0];
        $digs = str_split(substr($x, 1));
        $carry = true;
        for ($i = count($digs) - 1; $carry && $i >= 0; $i--) {
            $d = strpos($digits, $digs[$i]) + 1;
            if ($d === strlen($digits)) {
                $digs[$i] = '0';
            } else {
                $digs[$i] = $digits[$d];
                $carry = false;
            }
        }
        if (!$carry) {
            return $head . implode('', $digs);
        }
        if ($head === 'Z') {
            return 'a0';
        }
        if ($head === 'z') {
            return null;
        }
        $h = chr(ord($head) + 1);
        if ($h > 'a') {
            $digs[] = '0';
        } else {
            array_pop($digs);
        }
        return $h . implode('', $digs);
    }

    private static function decrementInteger(string $x): ?string
    {
        $digits = self::DIGITS;
        $last = substr($digits, -1);
        $head = $x[0];
        $digs = str_split(substr($x, 1));
        $borrow = true;
        for ($i = count($digs) - 1; $borrow && $i >= 0; $i--) {
            $d = strpos($digits, $digs[$i]) - 1;
            if ($d === -1) {
                $digs[$i] = $last;
            } else {
                $digs[$i] = $digits[$d];
                $borrow = false;
            }
        }
        if (!$borrow) {
            return $head . implode('', $digs);
        }
        if ($head === 'a') {
            return 'Z' . $last;
        }
        if ($head === 'A') {
            return null;
        }
        $h = chr(ord($head) - 1);
        if ($h < 'Z') {
            $digs[] = $last;
        } else {
            array_pop($digs);
        }
        return $h . implode('', $digs);
    }

    /** A key strictly between $a and $b (null = open end). */
    public static function keyBetween(?string $a, ?string $b): string
    {
        if ($a !== null && !self::isValidKey($a)) {
            throw new \InvalidArgumentException("invalid order key: $a");
        }
        if ($b !== null && !self::isValidKey($b)) {
            throw new \InvalidArgumentException("invalid order key: $b");
        }
        if ($a !== null && $b !== null && strcmp($a, $b) >= 0) {
            throw new \InvalidArgumentException("keyBetween: $a >= $b");
        }
        if ($a === null) {
            if ($b === null) {
                return 'a0';
            }
            $ib = self::integerPart($b);
            $fb = substr($b, strlen($ib));
            if ($ib === self::SMALLEST_INTEGER) {
                return $ib . self::midpoint('', $fb);
            }
            if (strcmp($ib, $b) < 0) {
                return $ib;
            }
            $res = self::decrementInteger($ib);
            if ($res === null) {
                throw new \RuntimeException('keyBetween: cannot decrement any more');
            }
            return $res;
        }
        if ($b === null) {
            $ia = self::integerPart($a);
            $fa = substr($a, strlen($ia));
            $i = self::incrementInteger($ia);
            return $i === null ? $ia . self::midpoint($fa, null) : $i;
        }
        $ia = self::integerPart($a);
        $fa = substr($a, strlen($ia));
        $ib = self::integerPart($b);
        $fb = substr($b, strlen($ib));
        if ($ia === $ib) {
            return $ia . self::midpoint($fa, $fb);
        }
        $i = self::incrementInteger($ia);
        if ($i === null) {
            throw new \RuntimeException('keyBetween: cannot increment any more');
        }
        if (strcmp($i, $b) < 0) {
            return $i;
        }
        return $ia . self::midpoint($fa, null);
    }

    /** $n ascending keys strictly between $a and $b. */
    public static function keysBetween(?string $a, ?string $b, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        if ($n === 1) {
            return [self::keyBetween($a, $b)];
        }
        if ($b === null) {
            $c = self::keyBetween($a, $b);
            $out = [$c];
            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::keyBetween($c, $b);
                $out[] = $c;
            }
            return $out;
        }
        if ($a === null) {
            $c = self::keyBetween($a, $b);
            $out = [$c];
            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::keyBetween($a, $c);
                $out[] = $c;
            }
            return array_reverse($out);
        }
        $mid = intdiv($n, 2);
        $c = self::keyBetween($a, $b);
        return array_merge(self::keysBetween($a, $c, $mid), [$c], self::keysBetween($c, $b, $n - $mid - 1));
    }
}
