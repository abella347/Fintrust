<?php

declare(strict_types=1);

namespace App\Core;

/** Minimal REST router. */
final class Router
{
    /**
     * @var array<int, array{method:string, pattern:string, regex:string, handler:callable|array, middleware:array}>
     */
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable|array $handler, array $middleware): void
    {
        // '/api/transactions/{id}' -> '#^/api/transactions/(?P<id>[A-Za-z0-9_-]+)$#'
        $regex = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', rtrim($path, '/') ?: '/');
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $path   = $request->path();
        $method = $request->method();
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            // 'RequirePermission:users.manage' -> new RequirePermission('users.manage')
            foreach ($route['middleware'] as $middleware) {
                [$class, $arg] = array_pad(explode(':', $middleware, 2), 2, null);
                ($arg === null ? new $class() : new $class($arg))->handle($request);
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $handler = [new $class(), $action];
            }
            $handler($request);
            return;
        }

        if ($pathMatched) {
            throw new HttpException(405, 'Method not allowed for this endpoint.');
        }
        throw new HttpException(404, 'Endpoint not found.');
    }
}
