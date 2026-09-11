<?php
// api/src/Router.php
declare(strict_types=1);

class Router
{
    /** @var array<array{string, string, array, bool}> */
    private array $routes = [];

    public function get(string $path, array $handler, bool $auth = false): void
    {
        $this->routes[] = ['GET', $path, $handler, $auth];
    }

    public function post(string $path, array $handler, bool $auth = false): void
    {
        $this->routes[] = ['POST', $path, $handler, $auth];
    }

    public function patch(string $path, array $handler, bool $auth = false): void
    {
        $this->routes[] = ['PATCH', $path, $handler, $auth];
    }

    public function put(string $path, array $handler, bool $auth = false): void
    {
        $this->routes[] = ['PUT', $path, $handler, $auth];
    }

    public function delete(string $path, array $handler, bool $auth = false): void
    {
        $this->routes[] = ['DELETE', $path, $handler, $auth];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Strip /api prefix when deployed under /api/
        if (str_starts_with($uri, '/api')) {
            $uri = substr($uri, 4);
        }
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as [$routeMethod, $routePath, $handler, $auth]) {
            [$pattern, $paramNames] = $this->compile($routePath);
            if ($routeMethod !== $method || !preg_match($pattern, $uri, $matches)) {
                continue;
            }
            $params = [];
            foreach ($paramNames as $name) {
                $params[$name] = $matches[$name];
            }
            [$class, $action] = $handler;
            if ($auth) {
                $userId = AuthMiddleware::authenticate();
                (new $class())->$action($params, $userId);
            } else {
                (new $class())->$action($params);
            }
            return;
        }

        Response::error('Not found', 404);
    }

    private function compile(string $path): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback(
            '/:(\w+)/',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path
        );
        return ['#^' . $pattern . '$#', $paramNames];
    }
}
