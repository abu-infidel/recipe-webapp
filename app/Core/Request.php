<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The incoming HTTP request, normalised.
 *
 * Built from the superglobals once in the front controller and passed down,
 * so nothing below the controller layer touches $_GET/$_SERVER directly.
 */
final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly string $host,
        public readonly bool $secure,
        public readonly string $ip,
        public readonly string $userAgent,
        private readonly ?string $rawBody = null,
    ) {}

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Percent-decode once. Persian slugs arrive encoded from every browser.
        $path = rawurldecode($path);
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        return new self(
            method:    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path:      $path,
            query:     $_GET,
            post:      $_POST,
            headers:   $headers,
            host:      self::resolveHost($headers),
            secure:    self::resolveSecure(),
            ip:        self::resolveIp(),
            userAgent: $headers['user-agent'] ?? '',
        );
    }

    /** Build a request by hand. Used by tests and the single-file exporter. */
    public static function fake(string $method, string $path, array $query = [], array $headers = []): self
    {
        return new self(
            method: strtoupper($method),
            path: '/' . trim($path, '/'),
            query: $query,
            post: [],
            headers: array_change_key_case($headers),
            host: $headers['host'] ?? 'localhost',
            secure: false,
            ip: '127.0.0.1',
            userAgent: $headers['user-agent'] ?? 'test',
        );
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody ?? (string) file_get_contents('php://input');
    }

    public function isGet(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** The path split into decoded segments, with empties removed. */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', trim($this->path, '/')), static fn($s) => $s !== ''));
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_ends_with($this->path, '.json');
    }

    /**
     * Hostname without port, lowercased.
     *
     * Taken from the Host header, which is attacker-controlled. Every use of
     * it in this codebase either compares against a configured allowlist or
     * falls back to the configured domain, so a forged Host cannot poison a
     * generated link or a cache key.
     */
    private static function resolveHost(array $headers): string
    {
        $host = $headers['host'] ?? (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return preg_match('/^[a-z0-9.\-]+$/', $host) === 1 ? $host : 'invalid';
    }

    private static function resolveSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // LiteSpeed behind the cPanel proxy sets this.
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Client IP.
     *
     * X-Forwarded-For is only honoured when the connecting address is a
     * loopback or private address, which on this host means LiteSpeed itself.
     * Trusting it unconditionally would let anyone spoof their way past the
     * rate limiter by sending a header.
     */
    private static function resolveIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $isPrivate = filter_var(
            $remote,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;

        if ($isPrivate && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($forwarded[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }
}
