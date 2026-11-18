<?php
declare(strict_types=1);

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:callable,auth:bool}> */
    private array $routes = [];

    /** @param callable $handler */
    public function add(string $method, string $path, $handler, bool $auth = true): void
    {
        $regex = '#^' . preg_replace('#\{id\}#', '(\d{1,18})', $path) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'auth' => $auth];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')), '/');
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');

        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                Auth::verifyCsrf();
            }
            $userId = $route['auth'] ? Auth::requireUserId() : 0;
            $id = isset($m[1]) ? (int) $m[1] : 0;
            ($route['handler'])($userId, $id);
            return;
        }
        if ($pathMatched) {
            throw new ApiError(405, 'method_not_allowed', 'ไม่รองรับการเรียกแบบนี้');
        }
        throw new ApiError(404, 'not_found', 'ไม่พบสิ่งที่ต้องการ');
    }
}
