<?php
declare(strict_types=1);

namespace App\Shared\Http;

use Throwable;
use App\Shared\Exceptions\HttpException;
use App\Shared\Exceptions\AuthenticationException;
use App\Shared\Exceptions\TokenExpiredException;
use App\Shared\Exceptions\InvalidTokenException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\MethodNotAllowedException;
use App\Shared\Exceptions\SecurityException;

/**
 * ErrorHandler - Manejo global de excepciones HTTP y de dominio.
 *
 * Responsabilidades:
 * - Renderizar respuestas JSON (API) o HTML (SPA) según el contexto
 * - Mapear excepciones de dominio a códigos HTTP correctos
 * - Ocultar detalles internos en producción
 * - Loguear errores en storage/logs/app.log
 * - Agregar headers específicos (Retry-After para 429)
 *
 * Soporta:
 * - HttpException (cualquier código HTTP)
 * - SecurityException (403/429/500 según contexto)
 * - AuthenticationException (401)
 * - TokenExpiredException (401)
 * - InvalidTokenException (401)
 * - NotFoundException (404)
 * - MethodNotAllowedException (405)
 */
class ErrorHandler
{
    private bool $debug;
    private string $logPath;

    public function __construct(bool $debug = false, ?string $logPath = null)
    {
        $this->debug = $debug;
        $this->logPath = $logPath ?? (defined('STORAGE_PATH') ? STORAGE_PATH . '/logs/app.log' : null);
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleException(Throwable $e): void
    {
        $this->log($e);

        if ($this->isApiRequest()) {
            $this->renderJson($e);
            return;
        }

        $this->renderHtml($e);
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalErrors = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
        if (!($error['type'] & $fatalErrors)) {
            return;
        }

        $e = new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );
        $this->handleException($e);
    }

    private function renderJson(Throwable $e): void
    {
        $statusCode = $this->resolveStatusCode($e);
        $message = $this->resolveMessage($e);

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        // Headers específicos según código de estado
        if ($statusCode === 429) {
            // Rate limit excedido → Retry-After header
            // SecurityException::rateLimitExceeded() incluye el retry_after en el mensaje
            $retryAfter = $this->extractRetryAfter($e);
            if ($retryAfter > 0) {
                header("Retry-After: {$retryAfter}");
                header('X-RateLimit-Limit: 0');
                header('X-RateLimit-Remaining: 0');
                header('X-RateLimit-Reset: ' . (time() + $retryAfter));
            }
        }

        if ($statusCode === 403) {
            header('X-Error-Category: forbidden');
        }

        $response = [
            'success' => false,
            'message' => $message,
            'error_code' => $statusCode,
        ];

        // Metadata adicional para errores de seguridad
        if ($e instanceof SecurityException) {
            $response['error_type'] = 'security';
        }

        if ($this->debug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function renderHtml(Throwable $e): void
    {
        $statusCode = $this->resolveStatusCode($e);
        $message = htmlspecialchars($this->resolveMessage($e), ENT_QUOTES, 'UTF-8');
        $title = $this->resolveTitle($statusCode);

        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');

        $debugBlock = '';
        if ($this->debug) {
            $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
            $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
            $line = $e->getLine();
            $class = get_class($e);
            $debugBlock = <<<HTML
            <div class="debug">
                <h3>Debug Info</h3>
                <p><strong>Exception:</strong> {$class}</p>
                <p><strong>File:</strong> {$file}:{$line}</p>
                <pre>{$trace}</pre>
            </div>
HTML;
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {$statusCode}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
        .card { background: #1e293b; padding: 2rem; border-radius: 0.5rem; max-width: 700px; width: 100%; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
        h1 { color: #f87171; margin-bottom: 1rem; font-size: 1.5rem; }
        p { color: #cbd5e1; line-height: 1.6; margin-bottom: 1rem; }
        .debug { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #334155; }
        .debug h3 { color: #fbbf24; margin-bottom: 0.5rem; font-size: 1rem; }
        .debug p { font-size: 0.85rem; margin-bottom: 0.5rem; }
        pre { background: #0f172a; padding: 1rem; border-radius: 0.25rem; overflow: auto; font-size: 0.8rem; color: #94a3b8; max-height: 300px; }
        .btn { display: inline-block; padding: 0.5rem 1rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 0.25rem; margin-top: 1rem; font-size: 0.9rem; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Error {$statusCode} - {$title}</h1>
        <p>{$message}</p>
        <a href="/" class="btn">Volver al inicio</a>
        {$debugBlock}
    </div>
</body>
</html>
HTML;
    }

    /**
     * Resuelve el código HTTP según el tipo de excepción.
     *
     * Orden de prioridad:
     * 1. SecurityException (403/429/500 según contexto)
     * 2. HttpException (cualquier código)
     * 3. AuthenticationException/TokenExpiredException/InvalidTokenException (401)
     * 4. NotFoundException (404)
     * 5. MethodNotAllowedException (405)
     * 6. Fallback (500)
     */
    private function resolveStatusCode(Throwable $e): int
    {
        // SecurityException: usa el código que ya trae (403, 429, 500, etc.)
        if ($e instanceof SecurityException) {
            $code = $e->getCode();
            // Asegurar que sea un código HTTP válido
            if ($code >= 400 && $code < 600) {
                return $code;
            }
            return 403; // Fallback por defecto para errores de seguridad
        }

        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        if ($e instanceof AuthenticationException
            || $e instanceof TokenExpiredException
            || $e instanceof InvalidTokenException) {
            return 401;
        }

        if ($e instanceof NotFoundException) {
            return 404;
        }

        if ($e instanceof MethodNotAllowedException) {
            return 405;
        }

        return 500;
    }

    private function resolveMessage(Throwable $e): string
    {
        if ($e instanceof SecurityException || $e instanceof HttpException) {
            $msg = $e->getMessage();
            return $msg !== '' ? $msg : $this->defaultMessage($this->resolveStatusCode($e));
        }

        if ($this->debug) {
            return $e->getMessage();
        }

        return 'Internal Server Error';
    }

    private function resolveTitle(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }

    private function defaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The requested resource was not found.',
            405 => 'The HTTP method is not allowed for this route.',
            409 => 'The request conflicts with the current state of the resource.',
            422 => 'The request was well-formed but contains validation errors.',
            429 => 'Too many requests. Please try again later.',
            500 => 'An unexpected error occurred.',
            503 => 'The service is temporarily unavailable.',
            default => 'An error occurred.',
        };
    }

    /**
     * Extrae el valor de Retry-After desde el mensaje de SecurityException.
     *
     * SecurityException::rateLimitExceeded() genera mensajes como:
     * "Rate limit exceeded. Retry after 60 seconds."
     *
     * @return int Segundos hasta reintentar (0 si no se puede extraer)
     */
    private function extractRetryAfter(Throwable $e): int
    {
        $message = $e->getMessage();
        if (preg_match('/Retry after (\d+) second/i', $message, $matches)) {
            return (int) $matches[1];
        }
        return 60; // Default: 60 segundos si no se puede extraer
    }

    private function isApiRequest(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (str_starts_with($path ?? '', '/api/')) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    private function log(Throwable $e): void
    {
        if ($this->logPath === null) {
            return;
        }

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $class = get_class($e);
        $message = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();

        // Categoría del error para facilitar análisis
        $category = $this->categorizeError($e);

        $entry = "[{$timestamp}] [{$category}] {$class}: {$message} in {$file}:{$line}\n";

        if ($this->debug) {
            $entry .= $e->getTraceAsString() . "\n";
        }

        $entry .= str_repeat('-', 80) . "\n";

        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Categoriza el error para facilitar análisis de logs.
     */
    private function categorizeError(Throwable $e): string
    {
        if ($e instanceof SecurityException) {
            return 'SECURITY';
        }
        if ($e instanceof AuthenticationException
            || $e instanceof TokenExpiredException
            || $e instanceof InvalidTokenException) {
            return 'AUTH';
        }
        if ($e instanceof NotFoundException) {
            return 'NOT_FOUND';
        }
        if ($e instanceof MethodNotAllowedException) {
            return 'METHOD_NOT_ALLOWED';
        }
        if ($e instanceof HttpException) {
            return 'HTTP';
        }
        return 'ERROR';
    }
}
