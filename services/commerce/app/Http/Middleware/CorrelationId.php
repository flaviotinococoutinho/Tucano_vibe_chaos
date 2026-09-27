<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the id Kong puts on every request (or creates one) in the Laravel
 * Context, so it shows up in every log line and follows queued jobs.
 */
final class CorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get(self::HEADER) ?: Uuid::uuid7()->toString();
        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
