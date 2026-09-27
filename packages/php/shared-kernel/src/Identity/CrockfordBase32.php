<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity;

use InvalidArgumentException;

/**
 * Crockford's Base32: no I, L, O or U, so codes survive being read aloud or
 * typed by hand. Decoding accepts lowercase and maps I/L to 1 and O to 0.
 */
final readonly class CrockfordBase32
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 13 symbols carry 65 bits; a positive 64-bit integer uses 63 of them. */
    public const int MAX_LENGTH = 13;

    private function __construct() {}

    public static function encode(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Only non-negative integers can be encoded.');
        }

        $encoded = '';
        do {
            $encoded = self::ALPHABET[$value % 32] . $encoded;
            $value = intdiv($value, 32);
        } while ($value > 0);

        return str_pad($encoded, self::MAX_LENGTH, '0', STR_PAD_LEFT);
    }

    public static function decode(string $encoded): int
    {
        $normalized = self::normalize($encoded);
        $value = 0;
        foreach (str_split($normalized) as $symbol) {
            $value = ($value << 5) | strpos(self::ALPHABET, $symbol);
        }

        return $value;
    }

    private static function normalize(string $encoded): string
    {
        $normalized = strtr(strtoupper(str_replace('-', '', $encoded)), ['I' => '1', 'L' => '1', 'O' => '0']);
        $length = strlen($normalized);

        if ($length === 0 || $length > self::MAX_LENGTH || strspn($normalized, self::ALPHABET) !== $length) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid Crockford Base32.', $encoded));
        }
        if ($length === self::MAX_LENGTH && $normalized[0] > '7') {
            throw new InvalidArgumentException(sprintf('"%s" does not fit in 63 bits.', $encoded));
        }

        return $normalized;
    }
}
