<?php

declare(strict_types=1);

namespace App\Core;

/** A read-only view of the current HTTP request. */
final class Request
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $body = null;

    /**
     * @var array<string, string> Route parameters such as {id}.
     */
    private array $params = [];

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // HTML forms can only send GET/POST, so allow _method=PUT|DELETE override on POST.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri  = rawurldecode($uri);
        $pos  = strpos($uri, '/api/');
        $path = $pos !== false ? substr($uri, $pos) : $uri;
        $path = '/' . trim($path, '/');
        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->body === null) {
            $this->body = [];
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($type, 'application/json')) {
                $raw = file_get_contents('php://input', false, null, 0, 1_048_576); // 1 MB cap
                $decoded = json_decode($raw ?: '', true);
                if ($raw !== '' && $raw !== false && !is_array($decoded)) {
                    throw new HttpException(400, 'Request body is not valid JSON.');
                }
                $this->body = $decoded ?? [];
            } else {
                $this->body = $_POST;
            }
        }
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** Trimmed string input; arrays/objects become '' so they can't sneak through. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function ip(): string
    {
        // REMOTE_ADDR is the only address the client cannot fake. Don't trust
        // X-Forwarded-For unless you deploy behind a proxy you control.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
    }

    /**
     * @param array<string, string> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }
}
