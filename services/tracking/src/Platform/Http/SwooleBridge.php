<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/** Converts Swoole's request and response objects to the plain ones the kernel works with, and back. */
final class SwooleBridge
{
    private function __construct() {}

    public static function request(SwooleRequest $request): Request
    {
        $server = $request->server ?? [];

        return new Request(
            method: strtoupper((string) ($server['request_method'] ?? 'GET')),
            path: (string) ($server['request_uri'] ?? '/'),
            query: (string) ($server['query_string'] ?? ''),
            headers: array_map(strval(...), $request->header ?? []),
        );
    }

    public static function send(Response $response, SwooleResponse $target): void
    {
        $target->status($response->status);
        foreach ($response->headers as $name => $value) {
            $target->header($name, $value);
        }
        $target->end($response->body);
    }
}
