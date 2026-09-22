<?php
declare(strict_types=1);

namespace App\Core;

/**
 * An outgoing HTTP response.
 *
 * Security headers are applied centrally in securityHeaders() so no route can
 * forget them. The Content-Security-Policy is the load-bearing one: it is
 * `self`-only, which is both a defence and the mechanism that guarantees the
 * site keeps rendering during an international blackout — there is nothing
 * foreign to fail to load.
 */
final class Response
{
    private array $headers = [];

    private function __construct(
        private string $body,
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['content-type' => 'text/html; charset=UTF-8', ...$headers]);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return new self($encoded, $status, ['content-type' => 'application/json; charset=UTF-8', ...$headers]);
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=UTF-8', ...$headers]);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'application/xml; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['location' => $location]);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    /**
     * Mark the response cacheable by LiteSpeed and by the browser.
     * Public pages only — never used on anything visitor-specific, because
     * nothing on this site is visitor-specific.
     */
    public function cacheFor(int $seconds): self
    {
        $this->headers['cache-control'] = "public, max-age={$seconds}";
        $this->headers['x-litespeed-cache-control'] = "public, max-age={$seconds}";

        return $this;
    }

    public function noCache(): self
    {
        $this->headers['cache-control'] = 'no-store, no-cache, must-revalidate';

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function send(bool $withSecurityHeaders = true): void
    {
        if (headers_sent()) {
            echo $this->body;
            return;
        }

        http_response_code($this->status);

        $headers = $withSecurityHeaders
            ? [...self::securityHeaders(), ...$this->headers]
            : $this->headers;

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // An ETag lets a returning reader revalidate cheaply, which matters
        // on the metered and intermittent connections this site is built for.
        if ($this->status === 200 && $this->body !== '' && !isset($headers['etag'])) {
            $etag = '"' . substr(sha1($this->body), 0, 27) . '"';
            header('ETag: ' . $etag);

            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                http_response_code(304);
                return;
            }
        }

        echo $this->body;
    }

    /**
     * @return array<string,string>
     */
    public static function securityHeaders(): array
    {
        $scriptOrigins = ["'self'"];
        $frameOrigins  = ["'none'"];
        $imageOrigins  = ["'self'", 'data:'];
        $connectOrigins = ["'self'"];

        // An ad network is the only thing permitted to widen this policy, and
        // only while it is explicitly switched on. Turning it on trades away
        // blackout resilience; the admin screen says so in those words.
        if (Config::bool('ads.network.enabled')) {
            foreach ((array) Config::get('ads.network.script_origins', []) as $origin) {
                $scriptOrigins[] = $origin;
                $imageOrigins[]  = $origin;
                $connectOrigins[] = $origin;
            }
            $frameOrigins = ["'self'", ...(array) Config::get('ads.network.script_origins', [])];
        }

        $csp = implode('; ', [
            "default-src 'self'",
            'script-src ' . implode(' ', $scriptOrigins),
            // Inline styles are used for per-field accent colours only.
            "style-src 'self' 'unsafe-inline'",
            'img-src ' . implode(' ', $imageOrigins),
            "font-src 'self'",
            'connect-src ' . implode(' ', $connectOrigins),
            'frame-src ' . implode(' ', $frameOrigins),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $headers = [
            'content-security-policy' => $csp,
            'x-content-type-options'  => 'nosniff',
            'x-frame-options'         => 'DENY',
            // Never leak which article a reader came from to an outbound link.
            'referrer-policy'         => 'no-referrer',
            'permissions-policy'      => 'geolocation=(), microphone=(), camera=(), interest-cohort=()',
            'cross-origin-opener-policy' => 'same-origin',
        ];

        if (Config::string('site.scheme') === 'https') {
            $headers['strict-transport-security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }
}
