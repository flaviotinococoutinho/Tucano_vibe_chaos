<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
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
    public function unexpected_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);

        $this->json('GET', '/test/crash');

        $this->response->assertInternalServerError()
            ->assertJsonPath('detail', 'Something went wrong on our side. Quote the correlation id when reporting it.');
        self::assertStringNotContainsString('hunter2', (string) $this->response->getContent());
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
