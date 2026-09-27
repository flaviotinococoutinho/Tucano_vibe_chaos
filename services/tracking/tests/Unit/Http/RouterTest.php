<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Platform\Http\HttpError;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;
use Tracking\Platform\Http\Route;
use Tracking\Platform\Http\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(
            new Route('GET', '/v1/couriers', static fn(): Response => new Response(200, 'list')),
            new Route('POST', '/v1/couriers', static fn(): Response => new Response(201, 'created')),
            new Route('GET', '/health/live', static fn(): Response => new Response(200, 'live')),
        );
    }

    #[Test]
    public function it_dispatches_by_method_and_path(): void
    {
        self::assertSame('list', $this->router->dispatch(new Request('GET', '/v1/couriers'))->body);
        self::assertSame('created', $this->router->dispatch(new Request('POST', '/v1/couriers'))->body);
        self::assertSame('live', $this->router->dispatch(new Request('GET', '/health/live'))->body);
    }

    #[Test]
    public function head_is_answered_by_the_get_handler(): void
    {
        self::assertSame('live', $this->router->dispatch(new Request('HEAD', '/health/live'))->body);
    }

    #[Test]
    public function the_handler_receives_the_request(): void
    {
        $router = new Router(new Route('GET', '/echo', static fn(Request $request): Response => new Response(200, $request->query)));

        self::assertSame('lat=-23.5', $router->dispatch(new Request('GET', '/echo', 'lat=-23.5'))->body);
    }

    #[Test]
    public function an_unknown_path_is_not_found(): void
    {
        $error = $this->dispatchExpectingError(new Request('GET', '/v1/nothing-here'));

        self::assertSame(404, $error->status);
        self::assertSame('The route /v1/nothing-here could not be found.', $error->getMessage());
    }

    #[Test]
    public function paths_match_exactly(): void
    {
        self::assertSame(404, $this->dispatchExpectingError(new Request('GET', '/health/live/'))->status);
    }

    #[Test]
    public function another_method_on_a_known_path_lists_the_allowed_ones(): void
    {
        $error = $this->dispatchExpectingError(new Request('DELETE', '/v1/couriers'));

        self::assertSame(405, $error->status);
        self::assertSame(['Allow' => 'GET, POST, HEAD'], $error->headers);
        self::assertSame('The DELETE method is not supported for route /v1/couriers. Supported methods: GET, POST, HEAD.', $error->getMessage());
    }

    #[Test]
    public function a_route_cannot_be_registered_twice(): void
    {
        $this->expectException(LogicException::class);

        new Router(
            new Route('GET', '/health/live', static fn(): Response => new Response(200)),
            new Route('GET', '/health/live', static fn(): Response => new Response(200)),
        );
    }

    private function dispatchExpectingError(Request $request): HttpError
    {
        try {
            $this->router->dispatch($request);
        } catch (HttpError $error) {
            return $error;
        }

        self::fail(sprintf('%s %s should not have a route.', $request->method, $request->path));
    }
}
