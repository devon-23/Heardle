<?php

declare(strict_types=1);

namespace HeardLive\Http;

/** Matches METHOD + path against patterns like /events/{id}. */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([a-z_][a-z0-9_]*)\}#i', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /**
     * @return array{status: 200, handler: callable, params: array<string, string>}
     *       | array{status: 404|405, allowed: list<string>}
     */
    public function match(string $method, string $path): array
    {
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return ['status' => 200, 'handler' => $route['handler'], 'params' => array_map('urldecode', $params)];
        }

        return $allowed === []
            ? ['status' => 404, 'allowed' => []]
            : ['status' => 405, 'allowed' => array_values(array_unique($allowed))];
    }
}
