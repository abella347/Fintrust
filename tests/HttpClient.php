<?php

declare(strict_types=1);

final class HttpClient
{
    /**
     * @var array<string, string>
     */
    public array $cookies = [];
    public ?string $csrf = null;
    public int $status = 0;
    /**
     * @var array<string, string> lower-cased header name => value (last one wins)
     */
    public array $headers = [];
    public string $body = '';

    public function __construct(private readonly string $base)
    {
    }

    /** Opens a page and remembers its CSRF token (from <meta name="csrf-token">). */
    public function page(string $path): string
    {
        $this->request('GET', $path);
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $this->body, $m)) {
            $this->csrf = $m[1];
        }
        return $this->body;
    }

    /** JSON API call. */
    public function api(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        if ($method !== 'GET') {
            $headers += ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string) $this->csrf];
        }
        $this->request($method, $path, $body === null ? null : json_encode($body), $headers + ['Accept' => 'application/json']);
        $json = json_decode($this->body, true);
        return is_array($json) ? $json : ['raw' => $this->body];
    }

    public function request(string $method, string $path, ?string $content = null, array $headers = []): void
    {
        if ($this->cookies) {
            $headers['Cookie'] = implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headerLines),
            'content'         => $content ?? '',
            'ignore_errors'   => true,   // Give us the body of 4xx/5xx responses too
            'follow_location' => 0,
            'timeout'         => 20,
        ]]);
        $this->body = (string) @file_get_contents($this->base . '/' . ltrim($path, '/'), false, $ctx);
        $responseHeaders = $http_response_header ?? [];
        $this->headers = [];
        $this->status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                $this->status = (int) $m[1];
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $this->headers[$name] = trim($value);
            if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
                if (str_contains(strtolower($value), 'expires=thu, 01 jan 1970') || $c[2] === '' || $c[2] === 'deleted') {
                    unset($this->cookies[$c[1]]);
                } else {
                    $this->cookies[$c[1]] = $c[2];
                }
            }
        }
    }

    public function sessionId(): ?string
    {
        return $this->cookies[(string) config('app.session.name')] ?? null;
    }
}
