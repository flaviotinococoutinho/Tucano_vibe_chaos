<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class ProblemDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Lumen binds every route closure to an object of its own, so these cannot be static.
        $this->app->router->get('/test/conflict', function (): never {
            throw self::outOfStock();
        });
        $this->app->router->post('/test/validation', function (Request $request, ValidationFactory $validation): array {
            return $validation->make($request->all(), ['sku' => 'required'])->validate();
        });
        $this->app->router->get('/test/crash', function (): never {
            throw new RuntimeException('database password is hunter2');
        });
        // What MySQL says through PDO when the connection is refused, and when it drops mid-query.
        $this->app->router->get('/test/database-refused', function (): never {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        });
        $this->app->router->get('/test/database-dropped', function (): never {
            throw new QueryException(
                'mysql',
                'select * from products where sku = ?',
                ['BOOK-DDD-001'],
                new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
            );
        });
        $this->app->router->get('/test/duplicate', function (): never {
            throw new QueryException(
                'mysql',
                'insert into products (sku) values (?)',
                ['BOOK-DDD-001'],
                new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'BOOK-DDD-001' for key 'products.products_sku_unique'"),
            );
        });
    }

    #[Test]
    public function domain_errors_become_http_status_by_category(): void
    {
        $this->json('GET', '/test/conflict', [], ['X-Correlation-Id' => 'req-1#1']);

        $this->response->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => 'about:blank',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Only 2 units of BOOK-DDD-001 left.',
                'instance' => '/test/conflict',
                'correlationId' => 'req-1#1',
            ]);
    }

    #[Test]
    public function domain_errors_are_answers_not_incidents(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        self::assertFalse($handler->shouldReport(self::outOfStock()));
        self::assertTrue($handler->shouldReport(new RuntimeException('database is gone')));
    }

    #[Test]
    public function validation_errors_list_each_field(): void
    {
        $this->json('POST', '/test/validation');

        $this->response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('errors.sku.0', 'The sku field is required.');
    }

    #[Test]
    public function unknown_routes_are_404_problems(): void
    {
        $this->json('GET', '/v1/nothing-here');

        $this->response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Not Found')
            ->assertJsonPath('detail', 'Not Found')
            ->assertJsonPath('instance', '/v1/nothing-here');
    }

    #[Test]
    public function a_wrong_method_says_which_ones_are_allowed(): void
    {
        $this->json('POST', '/health/live');

        $this->response->assertStatus(405)
            ->assertHeader('Allow', 'GET')
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function unexpected_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);

        $this->json('GET', '/test/crash');

        $this->response->assertInternalServerError()
            ->assertJsonPath('detail', 'Something went wrong on our side. Quote the correlation id when reporting it.');
        self::assertStringNotContainsString('hunter2', (string) $this->response->getContent());
    }

    #[Test]
    public function a_database_that_does_not_answer_is_a_503_that_says_when_to_try_again(): void
    {
        $this->json('GET', '/test/database-refused');

        $this->response->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Service Unavailable')
            ->assertJsonPath('detail', 'The database is not answering right now. Try again in a few seconds.');
    }

    #[Test]
    public function a_connection_dropped_in_the_middle_of_a_query_is_an_outage_too(): void
    {
        $this->json('GET', '/test/database-dropped');

        $this->response->assertServiceUnavailable()->assertHeader('Retry-After', '5');
    }

    #[Test]
    public function a_query_the_database_refused_is_still_a_hidden_500(): void
    {
        config(['app.debug' => false]);

        $this->json('GET', '/test/duplicate');

        $this->response->assertInternalServerError();
        self::assertFalse($this->response->headers->has('Retry-After'));
        self::assertStringNotContainsString('products_sku_unique', (string) $this->response->getContent());
    }

    private static function outOfStock(): DomainError
    {
        return new class ('Only 2 units of BOOK-DDD-001 left.') extends DomainError {
            public function category(): ErrorCategory
            {
                return ErrorCategory::Conflict;
            }
        };
    }
}
