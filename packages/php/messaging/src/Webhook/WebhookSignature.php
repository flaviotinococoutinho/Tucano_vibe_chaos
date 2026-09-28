<?php

declare(strict_types=1);

namespace Tucano\Messaging\Webhook;

/**
 * The signature of the webhooks of the lab's partners (PayFake, CarrierFake),
 * the scheme Stripe made common: `t=<unix seconds>,v1=<hex>`, where the hex is
 * HMAC-SHA256 with the shared secret over `<t>.<raw body>`. The timestamp is
 * signed too, so an old request replayed later is refused, and several v1 may
 * come while a secret is rotated. Five minutes of tolerance is Stripe's
 * default; each service sets its own from the environment.
 */
final readonly class WebhookSignature
{
    public function __construct(private string $secret, private int $toleranceSeconds = 300) {}

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
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
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
