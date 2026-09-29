<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\Http;

/**
 * The signature of a courier's report: `t=<unix seconds>,v1=<hex>`, where the hex is
 * HMAC-SHA256 with the shared secret over `<t>.<raw body>`, the scheme of every webhook in
 * the lab. It is a copy of Tucano\Messaging\Webhook\WebhookSignature: tracking does not
 * depend on the messaging package, which needs rdkafka and PDO for everything else it does.
 */
final readonly class CourierSignature
{
    public const string HEADER = 'Courier-Signature';

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
            // Constant time: the comparison must not tell how many characters matched.
            if (hash_equals($expected, $candidate)) {
                return SignatureVerdict::Valid;
            }
        }

        return SignatureVerdict::Mismatch;
    }
}
