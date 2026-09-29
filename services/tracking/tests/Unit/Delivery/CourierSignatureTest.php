<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Delivery\Adapter\Driving\Http\CourierSignature;
use Tracking\Delivery\Adapter\Driving\Http\SignatureVerdict;

/** The same verdicts as partners-sim's verify() and Tucano\Messaging\Webhook\WebhookSignature. */
final class CourierSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_local_couriers';
    private const string BODY = '{"type":"ended","trackingCode":"TX02Q6AGJQ45G00","outcome":"delivered","at":"2026-09-28T21:56:33.101Z"}';
    private const int NOW = 1_790_000_000;

    #[Test]
    public function a_report_signed_with_the_shared_secret_is_valid(): void
    {
        $header = sprintf('t=%d,v1=%s', self::NOW, hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET));

        self::assertSame(SignatureVerdict::Valid, new CourierSignature(self::SECRET)->verify(self::BODY, $header, self::NOW + 10));
        self::assertSame(SignatureVerdict::Valid, new CourierSignature(self::SECRET)->verify(self::BODY, 'v1=' . str_repeat('0', 64) . ',' . $header, self::NOW), 'any v1 may match, for a secret being rotated');
    }

    #[Test]
    public function anything_else_is_refused_with_its_reason(): void
    {
        $signature = new CourierSignature(self::SECRET);
        $header = sprintf('t=%d,v1=%s', self::NOW, hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET));

        self::assertSame(SignatureVerdict::Malformed, $signature->verify(self::BODY, '', self::NOW));
        self::assertSame(SignatureVerdict::Stale, $signature->verify(self::BODY, $header, self::NOW + 301));
        self::assertSame(SignatureVerdict::Mismatch, $signature->verify(self::BODY . ' ', $header, self::NOW));
        self::assertSame(SignatureVerdict::Mismatch, new CourierSignature('another secret')->verify(self::BODY, $header, self::NOW));
    }
}
