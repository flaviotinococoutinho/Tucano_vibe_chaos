<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Closure;
use LogicException;

/** Maps method and path to a handler. Paths match exactly, and every GET route answers HEAD too. */
final readonly class Router
{
    /** @var array<string, array<string, Closure(Request): Response>> handlers by path, then by method */
    private array $handlers;

    public function __construct(Route ...$routes)
    {
        $handlers = [];
        foreach ($routes as $route) {
            if (isset($handlers[$route->path][$route->method])) {
                throw new LogicException(sprintf('%s %s is routed twice.', $route->method, $route->path));
            }
            $handlers[$route->path][$route->method] = $route->handler;
        }
        foreach ($handlers as $path => $byMethod) {
            if (isset($byMethod['GET'])) {
                $handlers[$path]['HEAD'] ??= $byMethod['GET'];
            }
        }
        $this->handlers = $handlers;
    }

    public function dispatch(Request $request): Response
    {
        $byMethod = $this->handlers[$request->path] ?? throw HttpError::notFound($request->path);
        $handler = $byMethod[$request->method]
            ?? throw HttpError::methodNotAllowed($request->method, $request->path, array_keys($byMethod));

        return $handler($request);
    }
}
