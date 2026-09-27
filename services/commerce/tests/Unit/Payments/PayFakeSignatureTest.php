<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Commerce\Payments\Adapter\Driving\Http\PayFakeSignature;
use Commerce\Payments\Adapter\Driving\Http\SignatureVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The same vector partners-sim checks, computed with `openssl dgst -sha256 -hmac`. */
final class PayFakeSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_local_payfake';

    private const string BODY = '{"id":"evt_01J8Z5W3Q4X9M2N7B8C6D5E4F3","type":"charge.succeeded"}';

    private const int SIGNED_AT = 1_790_510_400;

    private const string HMAC = '0605457e458dd9fea675921d4df5754d4330d115baa8c60beb64f86dc29f4061';

    #[Test]
    public function it_accepts_the_signature_partners_sim_makes(): void
    {
        self::assertSame(SignatureVerdict::Valid, $this->verify(self::BODY, sprintf('t=%d,v1=%s', self::SIGNED_AT, self::HMAC)));
    }

    #[Test]
    public function one_character_changed_in_the_body_is_a_mismatch(): void
    {
        self::assertSame(SignatureVerdict::Mismatch, $this->verify(str_replace('succeeded', 'Succeeded', self::BODY), sprintf('t=%d,v1=%s', self::SIGNED_AT, self::HMAC)));
    }

    #[Test]
    public function a_fresh_timestamp_on_an_old_signature_is_a_mismatch(): void
    {
        self::assertSame(SignatureVerdict::Mismatch, $this->verify(self::BODY, sprintf('t=%d,v1=%s', self::SIGNED_AT + 60, self::HMAC), self::SIGNED_AT + 60));
    }

    #[Test]
    public function more_than_five_minutes_away_is_stale(): void
    {
        self::assertSame(SignatureVerdict::Stale, $this->verify(self::BODY, sprintf('t=%d,v1=%s', self::SIGNED_AT, self::HMAC), self::SIGNED_AT + 301));
    }

    #[Test]
    public function a_header_without_time_or_signature_is_malformed(): void
    {
        self::assertSame(SignatureVerdict::Malformed, $this->verify(self::BODY, 'v1=' . self::HMAC));
        self::assertSame(SignatureVerdict::Malformed, $this->verify(self::BODY, 't=' . self::SIGNED_AT));
        self::assertSame(SignatureVerdict::Malformed, $this->verify(self::BODY, ''));
    }

    #[Test]
    public function during_a_secret_rotation_one_matching_signature_is_enough(): void
    {
        $header = sprintf('t=%d,v1=%s,v1=%s', self::SIGNED_AT, str_repeat('0', 64), self::HMAC);

        self::assertSame(SignatureVerdict::Valid, $this->verify(self::BODY, $header));
    }

    private function verify(string $body, string $header, int $now = self::SIGNED_AT): SignatureVerdict
    {
        return new PayFakeSignature(self::SECRET)->verify($body, $header, $now);
    }
}
