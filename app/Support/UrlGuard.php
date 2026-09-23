<?php
declare(strict_types=1);

namespace App\Support;

/**
 * URL and path validation for values that end up in href/src attributes.
 *
 * Escaping with e() stops a value breaking out of its attribute, but it does
 * nothing about a perfectly well-escaped `javascript:alert(1)`. Anything that
 * becomes a link or an image source goes through here as well.
 */
final class UrlGuard
{
    /** An absolute http(s) URL with a real hostname. */
    public static function isHttpUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        // Credentials in a URL are a phishing tell ("https://bank.example@evil.test").
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9.\-]+$/', $parts['host']) === 1 && str_contains($parts['host'], '.');
    }

    /**
     * A path relative to the media directory, as stored in media.path.
     *
     * Letters, digits, dash, underscore, dot and slash only, never starting
     * with a slash or a dot and never containing "..", so it cannot climb out
     * of /media or be read as a URL.
     */
    public static function isMediaPath(?string $path): bool
    {
        if ($path === null || $path === '' || strlen($path) > 400) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9][A-Za-z0-9/_\-.]*$#', $path) === 1
            && !str_contains($path, '..')
            && !str_contains($path, '//');
    }

    /** The URL to render for a stored http(s) URL, or null to render nothing. */
    public static function safeHref(?string $url): ?string
    {
        return self::isHttpUrl($url) ? trim((string) $url) : null;
    }
}
