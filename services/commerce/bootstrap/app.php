<?php

declare(strict_types=1);

use App\Http\Middleware\CorrelationId;
use App\Http\ProblemDetails;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Tucano\SharedKernel\Domain\DomainError;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->prepend(CorrelationId::class);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Business outcomes (out of stock, invalid transition) are answers, not incidents.
        $exceptions->dontReport(DomainError::class);
        $exceptions->render(static fn(Throwable $error, Request $request) => ProblemDetails::from($error, $request));
    })
    ->create();
