<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
    public function unexpected_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/test/crash')->assertInternalServerError();

        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
    }
}
