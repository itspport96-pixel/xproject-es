<?php
declare(strict_types=1);

namespace App\Shared\Router;

use App\Shared\Exceptions\HttpException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\MethodNotAllowedException;
use ReflectionMethod;

/**
 * Router - Despachador HTTP con soporte para middlewares en cadena.
 *
 * Flujo de ejecución:
 *  1. Construir $request con toda la info HTTP (headers, body, query, ip, etc.)
 *  2. Ejecutar middlewares en cadena (cada uno recibe $request y retorna $request modificado)
 *  3. Ejecutar handler (controller method) con $request y $params
 *
 * Los middlewares pueden inyectar datos en $request:
 *  - AuthenticationMiddleware → $request['user'] (claims JWT)
 *  - TenantMiddleware → $request['tenant'] (datos del tenant)
 *  - RateLimitMiddleware → headers X-RateLimit-*
 *  - PermissionMiddleware → (solo valida, no inyecta)
 *
 * Los controllers reciben $request si su firma lo acepta (compatibilidad hacia atrás
 * mediante ReflectionMethod).
 */
final class Router
{
    private array $routes = [];
    private string $currentGroupPrefix = '';
    private array $currentGroupMiddleware = [];

    public function __construct() {}

    public function get(string $path, $handler, array $middleware = []): void { $this->addRoute('GET', $path, $handler, $middleware); }
    public function post(string $path, $handler, array $middleware = []): void { $this->addRoute('POST', $path, $handler, $middleware); }
    public function put(string $path, $handler, array $middleware = []): void { $this->addRoute('PUT', $path, $handler, $middleware); }
    public function patch(string $path, $handler, array $middleware = []): void { $this->addRoute('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, $handler, array $middleware = []): void { $this->addRoute('DELETE', $path, $handler, $middleware); }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->currentGroupPrefix;
        $previousMiddleware = $this->currentGroupMiddleware;

        $this->currentGroupPrefix .= $prefix;
        $this->currentGroupMiddleware = array_merge($this->currentGroupMiddleware, $middleware);

        $callback($this);

        $this->currentGroupPrefix = $previousPrefix;
        $this->currentGroupMiddleware = $previousMiddleware;
    }

    public function loadRoutes(string $file): void
    {
        if (file_exists($file)) {
            $router = $this;
            require $file;
        }
    }

    /**
     * Despacha la request HTTP.
     *
     * Flujo:
     *  1. Construir $request con toda la info HTTP
     *  2. Ejecutar middlewares en cadena
     *  3. Ejecutar handler con $request y $params
     */
    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($uri);

        // ============================================
        // 1. Construir $request con toda la info HTTP
        // ============================================
        $request = $this->buildRequest($method, $uri, $path);

        // ============================================
        // 2. Buscar ruta coincidente
        // ============================================
        $matchedRoute = null;
        $matchedParams = [];

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            $params = $this->matchRoute($route['path'], $path);
            if ($params !== false) {
                $matchedRoute = $route;
                $matchedParams = $params;
                break;
            }
        }

        if ($matchedRoute === null) {
            throw new NotFoundException("Route not found: {$method} {$path}");
        }

        // Route params se pasan via $request['params'] y $params al handler
        // NO se inyectan en $_GET para evitar contaminación del super-global
        $request['params'] = $matchedParams;

        // ============================================
        // 3. Ejecutar middlewares en cadena
        //    + 4. Ejecutar handler
        //
        // El hook de auditoría ($request['audit_finish'], instalado por
        // AuditMiddleware) se invoca SIEMPRE: tanto en respuesta normal como
        // en excepción (401/403/429/errores de negocio), de modo que las
        // escrituras queden registradas con su resultado real.
        // ============================================
        $statusCode = null;
        $result = null;

        try {
            $request = $this->executeMiddleware($matchedRoute['middleware'], $request);
            $result = $this->executeHandler($matchedRoute['handler'], $request, $matchedParams);
            $statusCode = is_array($result) ? (int) ($result['status'] ?? 200) : 200;
            $this->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->notifyAuditFinish($request, null, $e, []);
            throw $e;
        }

        $this->notifyAuditFinish($request, $statusCode, null, is_array($result) ? $result : []);
    }

    /**
     * Invoca el hook de auditoría instalado por AuditMiddleware, si existe.
     *
     * Un fallo DENTRO de la auditoría jamás debe romper la respuesta HTTP
     * (AuditLogger ya degrada a error_log, aquí se añade una red de seguridad).
     */
    private function notifyAuditFinish(array $request, ?int $status, ?\Throwable $error, array $result): void
    {
        $finish = $request['audit_finish'] ?? null;

        if (!is_callable($finish)) {
            return;
        }

        try {
            $finish($status, $error, $result, $request);
        } catch (\Throwable $e) {
            error_log('[Audit] hook audit_finish falló: ' . $e->getMessage());
        }
    }

    /**
     * Construye el array $request con toda la info HTTP.
     *
     * @return array{method: string, uri: string, path: string, headers: array, query: array, body: array, params: array, ip: string, user_agent: string, raw_body: string}
     */
    private function buildRequest(string $method, string $uri, string $path): array
    {
        // Headers (normalizados a lowercase)
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }
        // Headers especiales
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        if (isset($_SERVER['AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // Body (JSON o form)
        $rawBody = '';
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // Tope de tamaño de payload (DoS de memoria)
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
                throw new HttpException('Payload demasiado grande (máximo 1MB)', 413);
            }
            $rawBody = (string) file_get_contents('php://input');
            if (str_contains($contentType, 'application/json') && $rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            } elseif (!empty($_POST)) {
                $body = $_POST;
            }
        }

        // Query params
        $query = $_GET;

        return [
            'method'      => $method,
            'uri'         => $uri,
            'path'        => $path,
            'headers'     => $headers,
            'query'       => $query,
            'body'        => $body,
            'params'      => [], // Se llena después del matchRoute
            'ip'          => $this->getClientIp(),
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'raw_body'    => $rawBody,
            'content_type' => $contentType,
        ];
    }

    /**
     * Ejecuta los middlewares en cadena.
     * Cada middleware recibe $request y debe retornar $request (posiblemente modificado).
     *
     * $request se pasa POR REFERENCIA: si un middleware lanza una excepción,
     * el caller conserva los datos ya inyectados por los middlewares previos
     * (claims de usuario/tenant) que la auditoría necesita para registrar el fallo.
     *
     * @param array $middleware Lista de middlewares (con posibles params: "Class:param1,param2")
     * @param array $request Request actual
     * @return array Request modificado por los middlewares
     */
    private function executeMiddleware(array $middleware, array &$request): array
    {
        foreach ($middleware as $middlewareClass) {
            $params = [];

            // Parsear params: "PermissionMiddleware:inventory.item.create"
            if (str_contains($middlewareClass, ':')) {
                [$middlewareClass, $paramString] = explode(':', $middlewareClass, 2);
                // El paramString entero es UN solo argumento del constructor:
                // el propio middleware hace su split de comas (permisos
                // "a,b" en PermissionMiddleware, limites "10,300" en
                // RateLimitMiddleware). Partirlo aqui romperia los
                // constructores con firma (?string $config, ?Servicio $svc),
                // al inyectar la 2da mitad como string en el servicio DI.
                $params = [trim($paramString)];
            }

            // Instanciar middleware con sus params
            $middlewareInstance = new $middlewareClass(...$params);

            // Ejecutar handle() pasando $request y recogiendo el retorno
            $result = $middlewareInstance->handle($request);

            // El middleware DEBE retornar el $request (modificado o no)
            if (is_array($result)) {
                $request = $result;
            }
        }

        return $request;
    }

    /**
     * Ejecuta el handler (controller method o callable).
     *
     * Usa ReflectionMethod para detectar la firma del método y pasar solo
     * los argumentos que acepta. Esto mantiene compatibilidad hacia atrás
     * con controllers que no reciben $request (ej: AuthController::login()).
     *
     * @param mixed $handler [ControllerClass, method] o callable
     * @param array $request Request con claims de usuario/tenant inyectados
     * @param array $params Route params (ej: ['id' => 'uuid-123'])
     * @return array Resultado JSON del handler (status + data/error)
     */
    private function executeHandler($handler, array $request, array $params): array
    {
        if (is_callable($handler) && !is_array($handler)) {
            $result = $handler($request, $params);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;

            // Instanciar controller (el constructor tiene fallback al Container)
            $controller = new $controllerClass();

            // Detectar firma del método con Reflection
            $reflection = new ReflectionMethod($controller, $method);
            $methodParams = $reflection->getParameters();

            // Construir argumentos según la firma
            $args = [];
            foreach ($methodParams as $param) {
                $paramName = $param->getName();

                if ($paramName === 'request') {
                    $args[] = $request;
                } elseif ($paramName === 'params') {
                    $args[] = $params;
                } else {
                    // Intentar pasar params por nombre (ej: $id, $roleId)
                    if (isset($params[$paramName])) {
                        $args[] = $params[$paramName];
                    } elseif ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } else {
                        $args[] = null;
                    }
                }
            }

            // Llamar al método con los argumentos detectados
            $result = $reflection->invokeArgs($controller, $args);
        } else {
            throw new \RuntimeException('Handler inválido');
        }

        if (!is_array($result)) {
            $result = ['status' => 200, 'data' => $result];
        }

        return $result;
    }

    private function sendJsonResponse($result): void
    {
        if (is_array($result)) {
            // v1.7.1 (A3): `_replay` es un marcador interno que el handler
            // reenvía para que notifyAuditFinish() (invocado DESPUÉS de aquí)
            // sepa que la respuesta es un replay idempotente y no escriba una
            // fila nueva en `audit_logs`. El array llega por valor, así que
            // retirarlo aquí no afecta a esa llamada y el body sigue siendo
            // idéntico al de la ejecución original.
            unset($result['_replay']);

            header('Content-Type: application/json; charset=utf-8');
            $status = $result['status'] ?? 200;
            http_response_code($status);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    private function addRoute(string $method, string $path, $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $this->currentGroupPrefix . $path,
            'handler' => $handler,
            'middleware' => array_merge($this->currentGroupMiddleware, $middleware),
        ];
    }

    private function matchRoute(string $routePath, string $requestPath): array|false
    {
        $routePath = $this->normalizePath($routePath);
        $requestPath = $this->normalizePath($requestPath);

        if (!str_contains($routePath, '{')) {
            return $routePath === $requestPath ? [] : false;
        }

        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = rtrim($path, '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    /**
     * Obtiene la IP real del cliente.
     * X-Forwarded-For es manipulable por el cliente: sólo se confía
     * cuando la conexión proviene de un proxy declarado en TRUSTED_PROXIES.
     */
    private function getClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))));

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim((string) $ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
