<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\LogContext;
use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the id Kong puts on every request (or creates one), writes it back on
 * the request for the code downstream and adds it to every log line.
 */
final readonly class CorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    public function __construct(private LogContext $logContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get(self::HEADER) ?: Uuid::uuid7()->toString();
        $request->headers->set(self::HEADER, $correlationId);
        $this->logContext->add(LogContext::CORRELATION_ID, $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
