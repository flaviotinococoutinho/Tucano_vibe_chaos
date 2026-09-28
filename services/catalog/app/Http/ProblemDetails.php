<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\CorrelationId;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\LostConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
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

    /**
     * A database that does not answer is an outage, not a bug: the same request works once it
     * is back, and the stack's MySQL restarts in about this long.
     */
    private const int DATABASE_RETRY_AFTER_SECONDS = 5;

    public static function from(Throwable $error, Request $request): JsonResponse
    {
        $status = self::statusOf($error);
        $title = Response::$statusTexts[$status] ?? 'Error';
        $problem = [
            'type' => self::typeOf($error),
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

        return new JsonResponse($problem, $status, [...self::headersOf($error), 'Content-Type' => 'application/problem+json']);
    }

    /**
     * What the client needs to act on the problem: Allow on a 405, Retry-After on a 429 or
     * while the database is out.
     *
     * @return array<mixed>
     */
    private static function headersOf(Throwable $error): array
    {
        return match (true) {
            $error instanceof HttpExceptionInterface => $error->getHeaders(),
            self::databaseIsOut($error) => ['Retry-After' => (string) self::DATABASE_RETRY_AFTER_SECONDS],
            default => [],
        };
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
            self::databaseIsOut($error) => Response::HTTP_SERVICE_UNAVAILABLE,
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
     * the Unavailable category, says something the client can act on. A database outage
     * says it in words of its own: the driver's message names the host and the port.
     */
    private static function detailOf(Throwable $error, int $status): string
    {
        if (self::databaseIsOut($error)) {
            return 'The database is not answering right now. Try again in a few seconds.';
        }
        $deliberate = $error instanceof HttpExceptionInterface || $error instanceof DomainError;
        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR && !$deliberate && !config('app.debug')) {
            return 'Something went wrong on our side. Quote the correlation id when reporting it.';
        }

        return $error->getMessage();
    }

    /**
     * A refused or lost connection, as opposed to a query the database refused. A QueryException
     * is a PDOException too. Lumen keeps Laravel's list of the words each driver uses for a lost
     * connection in a trait, and this borrows it.
     */
    private static function databaseIsOut(Throwable $error): bool
    {
        if ($error instanceof LostConnectionException) {
            return true;
        }
        if (!$error instanceof PDOException) {
            return false;
        }
        $detector = new class {
            use DetectsLostConnections;

            public function lost(Throwable $error): bool
            {
                return $this->causedByLostConnection($error);
            }
        };

        return $detector->lost($error);
    }
}
