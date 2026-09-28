<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Domain\ProblemType;

/** Every error leaves the service as RFC 9457 application/problem+json. */
final readonly class ProblemDetails
{
    /** Where each named problem is explained; the type of a problem is this page plus its name. */
    private const string PROBLEMS = 'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/problems.md';

    public static function from(Throwable $error, Request $request): JsonResponse
    {
        $status = self::statusOf($error);
        $problem = [
            'type' => self::typeOf($error),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => self::detailOf($error, $status),
            'instance' => $request->getRequestUri(),
            'correlationId' => Context::get('correlation_id'),
        ];
        if ($error instanceof ValidationException) {
            $problem['errors'] = $error->errors();
        }

        // Some HTTP errors carry headers the client needs: Allow on a 405, Retry-After on a 429.
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

        return new JsonResponse($problem, $status, [...$headers, 'Content-Type' => 'application/problem+json']);
    }

    /**
     * RFC 9457: a domain error that declares a ProblemType gets the URI of the page that
     * explains it, so a client can tell apart two problems with the same status. Every
     * other error is about:blank, which means the status says it all.
     */
    private static function typeOf(Throwable $error): string
    {
        $name = $error instanceof DomainError ? ProblemType::of($error) : null;

        return $name === null ? 'about:blank' : self::PROBLEMS . '#' . $name;
    }

    private static function statusOf(Throwable $error): int
    {
        return match (true) {
            $error instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            $error instanceof DomainError => self::statusOfCategory($error->category()),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private static function statusOfCategory(ErrorCategory $category): int
    {
        return match ($category) {
            ErrorCategory::NotFound => Response::HTTP_NOT_FOUND,
            ErrorCategory::Conflict => Response::HTTP_CONFLICT,
            ErrorCategory::InvalidInput => Response::HTTP_UNPROCESSABLE_ENTITY,
            ErrorCategory::Forbidden => Response::HTTP_FORBIDDEN,
            ErrorCategory::Unavailable => Response::HTTP_SERVICE_UNAVAILABLE,
        };
    }

    /**
     * Only the unexpected is hidden. A 503 raised on purpose, or a domain error of
     * the Unavailable category, says something the client can act on.
     */
    private static function detailOf(Throwable $error, int $status): string
    {
        $deliberate = $error instanceof HttpExceptionInterface || $error instanceof DomainError;
        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR && !$deliberate && !config('app.debug')) {
            return 'Something went wrong on our side. Quote the correlation id when reporting it.';
        }

        return $error->getMessage();
    }
}
