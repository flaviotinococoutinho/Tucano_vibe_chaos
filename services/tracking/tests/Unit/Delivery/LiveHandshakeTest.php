<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Delivery\Adapter\Driving\Http\FollowLiveController;
use Tracking\Platform\Http\HttpError;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\ValidationFailed;

final class LiveHandshakeTest extends TestCase
{
    /** The example of RFC 6455, section 1.3. */
    private const string KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    #[Test]
    public function a_websocket_handshake_for_a_tracking_code_switches_protocols(): void
    {
        $response = new FollowLiveController()(self::handshake('trackingCode=TX02Q6AGJQ45G00'));

        self::assertSame(101, $response->status);
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response->headers['Sec-WebSocket-Accept']);
        self::assertSame('websocket', $response->headers['Upgrade']);
    }

    #[Test]
    public function a_plain_request_is_told_to_upgrade(): void
    {
        try {
            new FollowLiveController()(new Request('GET', '/v1/live', 'trackingCode=TX02Q6AGJQ45G00'));
            self::fail('A plain GET switched protocols.');
        } catch (HttpError $error) {
            self::assertSame(426, $error->status);
            self::assertSame('websocket', $error->headers['Upgrade']);
        }
    }

    #[Test]
    public function a_handshake_without_a_valid_tracking_code_is_refused(): void
    {
        $this->expectException(ValidationFailed::class);

        new FollowLiveController()(self::handshake('trackingCode=TX0000'));
    }

    #[Test]
    public function a_handshake_with_a_broken_key_is_a_bad_request(): void
    {
        try {
            new FollowLiveController()(new Request('GET', '/v1/live', 'trackingCode=TX02Q6AGJQ45G00', ['Upgrade' => 'websocket', 'Sec-WebSocket-Key' => 'short']));
            self::fail('A handshake with a broken key switched protocols.');
        } catch (HttpError $error) {
            self::assertSame(400, $error->status);
        }
    }

    private static function handshake(string $query): Request
    {
        return new Request('GET', '/v1/live', $query, ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => self::KEY, 'Sec-WebSocket-Version' => '13']);
    }
}
