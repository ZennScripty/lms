<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Small validation helpers. All methods throw InvalidArgumentException with a user-safe message. */
final class Validator
{
    public static function str(mixed $v, string $label, int $min = 1, int $max = 255): string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        $len = mb_strlen($s);
        if ($len < $min || $len > $max) {
            throw new InvalidArgumentException("$label must be between $min and $max characters.");
        }
        return $s;
    }

    public static function optStr(mixed $v, string $label, int $max = 255): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        if ($s === '') {
            return null;
        }
        return self::str($s, $label, 1, $max);
    }

    public static function int(mixed $v, string $label, ?int $min = null, ?int $max = null): int
    {
        if (is_int($v)) {
            $i = $v;
        } elseif (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))) {
            $i = (int) trim($v);
        } else {
            throw new InvalidArgumentException("$label must be a whole number.");
        }
        if (($min !== null && $i < $min) || ($max !== null && $i > $max)) {
            throw new InvalidArgumentException("$label is out of range.");
        }
        return $i;
    }

    public static function optInt(mixed $v, string $label): ?int
    {
        if ($v === null || $v === '' || $v === '0' || $v === 0) {
            return null;
        }
        return self::int($v, $label, 1);
    }

    /** @param array<int,string> $allowed */
    public static function inList(mixed $v, array $allowed, string $label): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        if (!in_array($s, $allowed, true)) {
            throw new InvalidArgumentException("$label has an invalid value.");
        }
        return $s;
    }

    public static function email(mixed $v, string $label = 'Email'): string
    {
        $s = self::str($v, $label, 3, 190);
        if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("$label is not a valid email address.");
        }
        return strtolower($s);
    }

    public static function date(mixed $v, string $label = 'Date'): string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) {
            throw new InvalidArgumentException("$label must be a valid date (YYYY-MM-DD).");
        }
        return $s;
    }

    public static function uuid(mixed $v, string $label = 'Event id'): string
    {
        $s = is_scalar($v) ? strtolower(trim((string) $v)) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $s)) {
            throw new InvalidArgumentException("$label is not a valid UUID.");
        }
        return $s;
    }

    public static function password(mixed $v): string
    {
        $s = is_string($v) ? $v : '';
        if (strlen($s) < 8 || strlen($s) > 200) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }
        return $s;
    }

    /** @return array<int,int> unique positive ints */
    public static function intList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (is_scalar($x) && preg_match('/^\d{1,18}$/', (string) $x) && (int) $x > 0) {
                $out[(int) $x] = (int) $x;
            }
        }
        return array_values($out);
    }
}
