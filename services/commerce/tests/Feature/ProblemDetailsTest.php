<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

        Route::get('/test/conflict', static function (): never {
            throw new class ('Only 2 units of BOOK-DDD-001 left.') extends DomainError {
                public function category(): ErrorCategory
                {
                    return ErrorCategory::Conflict;
                }
            };
        });
        Route::post('/test/validation', static fn(Request $request) => $request->validate(['sku' => 'required']));
        Route::get('/test/crash', static fn() => throw new RuntimeException('database password is hunter2'));
        // What PostgreSQL says through PDO when the connection is refused, and when it drops mid-query.
        Route::get('/test/database-refused', static fn() => throw new PDOException(
            'SQLSTATE[08006] [7] connection to server at "toxiproxy" (172.19.0.7), port 15432 failed: Connection refused',
        ));
        Route::get('/test/database-dropped', static fn() => throw new QueryException(
            'pgsql',
            'select * from orders where id = ?',
            ['01a0e95d-09c6-7172-8e08-ea4eb836347b'],
            new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly'),
        ));
        Route::get('/test/duplicate', static fn() => throw new QueryException(
            'pgsql',
            'insert into orders (id) values (?)',
            ['01a0e95d-09c6-7172-8e08-ea4eb836347b'],
            new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "orders_pkey"'),
        ));
    }

    #[Test]
    public function domain_errors_become_http_status_by_category(): void
    {
        $this->getJson('/test/conflict', ['X-Correlation-Id' => 'req-1#1'])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'type' => 'about:blank',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Only 2 units of BOOK-DDD-001 left.',
                'instance' => '/test/conflict',
                'correlationId' => 'req-1#1',
            ]);
    }

    #[Test]
    public function validation_errors_list_each_field(): void
    {
        $this->postJson('/test/validation', [])
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['errors' => ['sku']]);
    }

    #[Test]
    public function unknown_routes_are_404_problems(): void
    {
        $this->getJson('/v1/nothing-here')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function a_wrong_method_says_which_ones_are_allowed(): void
    {
        $this->postJson('/health/live')
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function unexpected_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/test/crash')->assertInternalServerError();

        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    #[Test]
    public function a_database_that_does_not_answer_is_a_503_that_says_when_to_try_again(): void
    {
        $response = $this->getJson('/test/database-refused')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Service Unavailable');

        // The driver's message names the host and the port; the problem never does.
        self::assertStringNotContainsString('toxiproxy', (string) $response->getContent());
        self::assertStringNotContainsString('15432', (string) $response->getContent());
    }

    #[Test]
    public function a_connection_dropped_in_the_middle_of_a_query_is_an_outage_too(): void
    {
        $this->getJson('/test/database-dropped')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5');
    }

    #[Test]
    public function a_query_the_database_refused_is_still_a_hidden_500(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/test/duplicate')->assertInternalServerError();

        self::assertFalse($response->headers->has('Retry-After'));
        self::assertStringNotContainsString('orders_pkey', (string) $response->getContent());
    }
}
