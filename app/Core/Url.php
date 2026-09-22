<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Canonical URL generation.
 *
 * Every link in the site is built here, so switching from path mode to
 * subdomain mode is a single config change with no template edits. In
 * subdomain mode the first path segment becomes the hostname:
 *
 *   path      →  https://example.ir/cooking/persian/stews
 *   subdomain →  https://cooking.example.ir/persian/stews
 */
final class Url
{
    public static function base(): string
    {
        return Config::string('site.scheme', 'https') . '://' . Config::string('site.domain');
    }

    public static function home(): string
    {
        return self::base() . '/';
    }

    /** Absolute URL for a field, given its materialised path. */
    public static function field(string $path): string
    {
        return self::fromContentPath($path);
    }

    /** Absolute URL for an article inside a field. */
    public static function article(string $fieldPath, string $slug): string
    {
        return self::fromContentPath(rtrim($fieldPath, '/') . '/' . $slug);
    }

    public static function search(string $query = ''): string
    {
        $url = self::base() . '/search';

        return $query === '' ? $url : $url . '?q=' . rawurlencode($query);
    }

    /**
     * Site-root-relative path for an asset, with a cache-busting fingerprint
     * derived from the file's modification time. Far-future caching is safe
     * because the URL changes whenever the file does — which matters on the
     * intermittent connections this site is built for.
     */
    public static function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__, 2) . '/public' . $path;

        if (is_file($file)) {
            $path .= '?v=' . substr((string) filemtime($file), -6);
        }

        return $path;
    }

    /**
     * Turn "cooking/persian/stews" into a full URL in whichever mode is
     * configured. Segments are individually encoded so Persian slugs survive
     * intact and a slash inside a slug cannot forge a path.
     */
    private static function fromContentPath(string $contentPath): string
    {
        $segments = array_values(array_filter(
            explode('/', trim($contentPath, '/')),
            static fn($s) => $s !== ''
        ));

        if ($segments === []) {
            return self::home();
        }

        $scheme = Config::string('site.scheme', 'https');
        $domain = Config::string('site.domain');

        if (Config::string('routing.mode', 'path') === 'subdomain') {
            $root = array_shift($segments);
            $host = $root . '.' . $domain;
            $rest = implode('/', array_map('rawurlencode', $segments));

            return $scheme . '://' . $host . '/' . $rest;
        }

        return $scheme . '://' . $domain . '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * The reverse of fromContentPath(): work out which content path a request
     * is asking for, accounting for the hostname in subdomain mode.
     *
     * Returns null when the host is not one of ours, so a forged Host header
     * cannot make the router resolve content it should not.
     */
    public static function contentPathFor(Request $request): ?string
    {
        $path = trim($request->path, '/');

        if (Config::string('routing.mode', 'path') !== 'subdomain') {
            return $path;
        }

        $domain = Config::string('site.domain');
        $host = $request->host;

        if ($host === $domain || $host === 'www.' . $domain) {
            return $path;
        }

        if (!str_ends_with($host, '.' . $domain)) {
            return null;
        }

        $sub = substr($host, 0, -strlen('.' . $domain));
        if (in_array($sub, (array) Config::get('routing.reserved_hosts', []), true)) {
            return null;
        }

        return $path === '' ? $sub : $sub . '/' . $path;
    }
}
