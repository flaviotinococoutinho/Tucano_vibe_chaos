<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tracking\Platform\Http\HttpError;
use Tracking\Platform\Http\ProblemDetails;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;
use Tracking\Platform\Http\ValidationFailed;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class ProblemDetailsTest extends TestCase
{
    #[Test]
    public function it_answers_with_the_same_members_as_commerce(): void
    {
        $response = ProblemDetails::from(
            self::domainError(ErrorCategory::Conflict, 'Courier 42 is already on a delivery.'),
            new Request('GET', '/v1/couriers/42', 'verbose=1'),
            'req-1#1',
        );

        self::assertSame(409, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Courier 42 is already on a delivery.',
            'instance' => '/v1/couriers/42?verbose=1',
            'correlationId' => 'req-1#1',
        ], self::decode($response));
    }

    #[Test]
    #[DataProvider('categories')]
    public function domain_errors_become_http_status_by_category(ErrorCategory $category, int $status, string $title): void
    {
        $response = ProblemDetails::from(self::domainError($category, 'Rule broken.'), new Request('GET', '/v1/couriers'), 'req-2#1');

        self::assertSame($status, $response->status);
        self::assertSame($title, self::decode($response)['title']);
    }

    /** @return iterable<string, array{ErrorCategory, int, string}> */
    public static function categories(): iterable
    {
        yield 'not found' => [ErrorCategory::NotFound, 404, 'Not Found'];
        yield 'conflict' => [ErrorCategory::Conflict, 409, 'Conflict'];
        yield 'invalid input' => [ErrorCategory::InvalidInput, 422, 'Unprocessable Content'];
        yield 'forbidden' => [ErrorCategory::Forbidden, 403, 'Forbidden'];
        yield 'unavailable' => [ErrorCategory::Unavailable, 503, 'Service Unavailable'];
    }

    #[Test]
    public function validation_errors_list_each_field(): void
    {
        $response = ProblemDetails::from(
            new ValidationFailed(['lat' => ['Must be between -90 and 90.']]),
            new Request('POST', '/v1/positions'),
            'req-3#1',
        );

        $problem = self::decode($response);
        self::assertSame(422, $problem['status']);
        self::assertSame(['lat' => ['Must be between -90 and 90.']], $problem['errors']);
    }

    #[Test]
    public function a_method_not_allowed_names_the_allowed_ones(): void
    {
        $response = ProblemDetails::from(
            HttpError::methodNotAllowed('POST', '/health/live', ['GET']),
            new Request('POST', '/health/live'),
            'req-4#1',
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
        self::assertSame('Method Not Allowed', self::decode($response)['title']);
    }

    #[Test]
    public function unexpected_errors_do_not_leak_internals(): void
    {
        $response = ProblemDetails::from(new RuntimeException('redis password is hunter2'), new Request('GET', '/v1/couriers'), 'req-5#1');

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('hunter2', $response->body);
        self::assertSame('Something went wrong on our side. Quote the correlation id when reporting it.', self::decode($response)['detail']);
    }

    #[Test]
    public function server_errors_from_the_domain_stay_hidden_too(): void
    {
        $response = ProblemDetails::from(self::domainError(ErrorCategory::Unavailable, 'redis:6379 refused'), new Request('GET', '/v1/couriers'), 'req-6#1');

        self::assertStringNotContainsString('redis:6379', $response->body);
    }

    #[Test]
    public function a_path_with_invalid_utf8_still_gets_an_answer(): void
    {
        $response = ProblemDetails::from(HttpError::notFound("/caf\xE9"), new Request('GET', "/caf\xE9"), 'req-7#1');

        self::assertSame("/caf\u{FFFD}", self::decode($response)['instance']);
    }

    private static function domainError(ErrorCategory $category, string $message): DomainError
    {
        return new class ($category, $message) extends DomainError {
            public function __construct(private readonly ErrorCategory $errorCategory, string $message)
            {
                parent::__construct($message);
            }

            public function category(): ErrorCategory
            {
                return $this->errorCategory;
            }
        };
    }

    /** @return array<mixed> */
    private static function decode(Response $response): array
    {
        $problem = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);

        return $problem;
    }
}
