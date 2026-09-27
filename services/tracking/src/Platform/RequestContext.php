<?php

declare(strict_types=1);

namespace Tracking\Platform;

use LogicException;
use Swoole\Coroutine;

/**
 * Data that belongs to one request lives in the context of the coroutine that
 * handles it. Swoole destroys that context when the coroutine ends, so nothing
 * leaks into the next request. A static property would leak: the worker process
 * outlives thousands of requests and serves many of them at the same time.
 */
final class RequestContext
{
    private const string CORRELATION_ID = 'correlation_id';

    private function __construct() {}

    public static function bindCorrelationId(string $correlationId): void
    {
        $context = Coroutine::getContext();
        if ($context === null) {
            throw new LogicException('Request data lives in a coroutine context; bind it from the coroutine that handles the request.');
        }
        $context[self::CORRELATION_ID] = $correlationId;
    }

    /**
     * Coroutines started while handling a request get an empty context of their
     * own, so the lookup walks up to the coroutine that bound the id.
     */
    public static function correlationId(): ?string
    {
        for ($coroutine = Coroutine::getCid(); $coroutine > 0; $coroutine = Coroutine::getPcid($coroutine)) {
            $correlationId = Coroutine::getContext($coroutine)[self::CORRELATION_ID] ?? null;
            if (is_string($correlationId)) {
                return $correlationId;
            }
        }

        return null;
    }
}
