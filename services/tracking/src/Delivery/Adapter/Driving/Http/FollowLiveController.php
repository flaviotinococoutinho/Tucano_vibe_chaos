<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\Http;

use InvalidArgumentException;
use Tracking\Delivery\Domain\TrackingCode;
use Tracking\Platform\Http\HttpError;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;
use Tracking\Platform\Http\ValidationFailed;

/**
 * GET /v1/live?trackingCode=: the handshake of a follower's WebSocket (RFC 6455). It goes
 * through the kernel like any request, so a refusal is the same problem details with the
 * correlation id; only a 101 turns the connection into a WebSocket (see bin/server.php).
 */
final readonly class FollowLiveController
{
    public const string PATH = '/v1/live';

    /** The fixed GUID of RFC 6455, section 4.2.2, that the accept key is derived with. */
    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __invoke(Request $request): Response
    {
        if (strtolower($request->header('Upgrade') ?? '') !== 'websocket') {
            throw HttpError::upgradeRequired(self::PATH);
        }
        self::trackingCodeOf($request);
        $key = $request->header('Sec-WebSocket-Key') ?? '';
        if (strlen((string) base64_decode($key, true)) !== 16) {
            throw HttpError::badRequest('The Sec-WebSocket-Key is not 16 bytes in Base64.');
        }

        return new Response(101, '', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => base64_encode(sha1($key . self::WEBSOCKET_GUID, true)),
            'Sec-WebSocket-Version' => '13',
        ]);
    }

    /** The code a connection follows, as the query of its handshake names it. */
    public static function trackingCodeOf(Request $request): TrackingCode
    {
        try {
            return TrackingCode::of($request->queryParameter('trackingCode') ?? '');
        } catch (InvalidArgumentException $invalid) {
            throw new ValidationFailed(['trackingCode' => [$invalid->getMessage()]]);
        }
    }
}
