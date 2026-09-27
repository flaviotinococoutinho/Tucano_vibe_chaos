<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** Every error leaves the service as RFC 9457 application/problem+json. */
final readonly class ProblemDetails
{
    public static function from(Throwable $error, Request $request): JsonResponse
    {
        $status = self::statusOf($error);
        $title = Response::$statusTexts[$status] ?? 'Error';
        $problem = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            // Lumen's router throws its 404 and 405 without a message.
            'detail' => self::detailOf($error, $status) ?: $title,
            'instance' => $request->getRequestUri(),
            'correlationId' => $request->headers->get(CorrelationId::HEADER),
        ];
        if ($error instanceof ValidationException) {
            $problem['errors'] = $error->errors();
        }

        // Some HTTP errors carry headers the client needs: Allow on a 405, Retry-After on a 429.
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];

        return new JsonResponse($problem, $status, [...$headers, 'Content-Type' => 'application/problem+json']);
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

    private static function detailOf(Throwable $error, int $status): string
    {
        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR && !config('app.debug')) {
            return 'Something went wrong on our side. Quote the correlation id when reporting it.';
        }

        return $error->getMessage();
    }
}
