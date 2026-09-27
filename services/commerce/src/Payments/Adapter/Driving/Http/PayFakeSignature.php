<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

/**
 * The PayFake-Signature header: `t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`,
 * the same check partners-sim exports. The time is inside the HMAC, so a captured
 * webhook cannot be replayed later with a fresh timestamp; several v1 values let
 * the secret rotate without downtime.
 */
final readonly class PayFakeSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private string $secret) {}

    public function verify(string $rawBody, string $header, int $now): SignatureVerdict
    {
        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            }
            if ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $candidates[] = strtolower($value);
            }
        }
        if ($timestamp === null || $candidates === []) {
            return SignatureVerdict::Malformed;
        }
        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return SignatureVerdict::Stale;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        foreach ($candidates as $candidate) {
            // Constant time: the comparison must not tell an attacker how many characters matched.
            if (hash_equals($expected, $candidate)) {
                return SignatureVerdict::Valid;
            }
        }

        return SignatureVerdict::Mismatch;
    }
}
