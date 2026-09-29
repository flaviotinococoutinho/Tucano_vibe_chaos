<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** Every error leaves the service as RFC 9457 application/problem+json, with the same members as commerce. */
final readonly class ProblemDetails
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /** Reason phrases (RFC 9110) of the statuses errors are answered with. */
    private const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        426 => 'Upgrade Required',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    private function __construct() {}

    public static function from(Throwable $error, Request $request, string $correlationId): Response
    {
        $status = self::statusOf($error);
        $problem = [
            'type' => 'about:blank',
            'title' => self::TITLES[$status] ?? 'Error',
            'status' => $status,
            'detail' => self::detailOf($error, $status),
            'instance' => $request->target(),
            'correlationId' => $correlationId,
        ];
        if ($error instanceof ValidationFailed) {
            $problem['errors'] = $error->errors;
        }

        $response = Response::json($problem, $status, self::CONTENT_TYPE);
        foreach ($error instanceof HttpError ? $error->headers : [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private static function statusOf(Throwable $error): int
    {
        return match (true) {
            $error instanceof ValidationFailed => 422,
            $error instanceof HttpError => $error->status,
            $error instanceof DomainError => self::statusOfCategory($error->category()),
            default => 500,
        };
    }

    private static function statusOfCategory(ErrorCategory $category): int
    {
        return match ($category) {
            ErrorCategory::NotFound => 404,
            ErrorCategory::Conflict => 409,
            ErrorCategory::InvalidInput => 422,
            ErrorCategory::Forbidden => 403,
            ErrorCategory::Unavailable => 503,
        };
    }

    /** The message of a server error may carry hosts, queries or secrets, so it stays in the logs. */
    private static function detailOf(Throwable $error, int $status): string
    {
        return $status >= 500
            ? 'Something went wrong on our side. Quote the correlation id when reporting it.'
            : $error->getMessage();
    }
}
