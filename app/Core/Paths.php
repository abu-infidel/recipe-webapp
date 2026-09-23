<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Filesystem locations, resolved in one place.
 *
 * The repository keeps the web root in `public/`, but cPanel serves
 * `public_html/`, and .cpanel.yml deploys into it. Code that hard-codes
 * `public/` works in development and fails silently in production: PHP writes
 * cached pages and the menu tree to a directory LiteSpeed never looks in, and
 * asset fingerprints vanish because the file-modified check finds nothing.
 * Every path below the web root goes through here.
 */
final class Paths
{
    private static ?string $public = null;

    /** The directory that contains app/, db/ and tools/. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The web root.
     *
     * An explicit `paths.public` setting wins. Otherwise public_html is used
     * when it exists beside app/ (a cPanel deploy), and public/ when it does
     * not (the repository and local development).
     */
    public static function public(): string
    {
        if (self::$public !== null) {
            return self::$public;
        }

        $configured = Config::string('paths.public');
        if ($configured !== '') {
            return self::$public = rtrim($configured, '/');
        }

        $cpanel = self::root() . '/public_html';

        return self::$public = is_dir($cpanel) ? $cpanel : self::root() . '/public';
    }

    public static function cache(): string
    {
        return self::public() . '/cache';
    }

    /** Rendered HTML pages served straight from disk by the web server. */
    public static function pages(): string
    {
        $configured = Config::string('cache.dir');

        return $configured !== '' ? rtrim($configured, '/') : self::cache() . '/pages';
    }

    public static function media(): string
    {
        return self::public() . '/media';
    }

    public static function themes(): string
    {
        return self::public() . '/themes';
    }

    /** Forget the resolved web root. Tests point it at a temporary tree. */
    public static function reset(): void
    {
        self::$public = null;
    }
}
