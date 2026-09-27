<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driving\Http;

use Closure;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\InvalidIdempotencyKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A POST that creates something must carry an Idempotency-Key, so the client
 * can retry after a timeout without creating it twice.
 */
final readonly class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (!is_string($key) || $key === '') {
            throw new BadRequestHttpException('The Idempotency-Key header is required; a new UUID per order works.');
        }
        try {
            IdempotencyKey::of($key);
        } catch (InvalidIdempotencyKey $invalid) {
            throw new BadRequestHttpException($invalid->getMessage(), $invalid);
        }

        return $next($request);
    }
}
