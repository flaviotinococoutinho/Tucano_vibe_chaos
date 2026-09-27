<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Psr\Log\LoggerInterface;
use Throwable;
use Tracking\Platform\RequestContext;
use Tucano\SharedKernel\Domain\DomainError;

/**
 * Handles one HTTP request from start to end: correlation id, routing and
 * errors. The Swoole callback only converts objects and calls this class.
 */
final readonly class Kernel
{
    public function __construct(
        private Router $router,
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request): Response
    {
        $startedAt = hrtime(true);
        $correlationId = CorrelationId::fromHeader($request->header(CorrelationId::HEADER))->value;
        RequestContext::bindCorrelationId($correlationId);

        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $error) {
            $this->report($error);
            $response = ProblemDetails::from($error, $request, $correlationId);
        }

        $this->logger->debug('Request handled', [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ]);
        $response = $response->withHeader(CorrelationId::HEADER, $correlationId);

        // Swoole writes whatever body it gets, even to a HEAD request, which must never carry one.
        return $request->method === 'HEAD' ? $response->withoutBody() : $response;
    }

    /** Unknown routes, invalid input and business rules are answers, not incidents. */
    private function report(Throwable $error): void
    {
        if ($error instanceof HttpError || $error instanceof ValidationFailed || $error instanceof DomainError) {
            return;
        }
        $this->logger->error($error->getMessage(), ['exception' => $error]);
    }
}
