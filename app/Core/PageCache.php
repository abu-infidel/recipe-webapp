<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Static HTML page cache.
 *
 * A published page is written to disk and .htaccess serves it directly, so a
 * cache hit never starts PHP. This is what lets a small shared host carry real
 * traffic, and it means the site keeps serving even while MariaDB is under
 * pressure.
 *
 * Layout mirrors the URL so the rewrite can find a file without any lookup:
 *
 *   https://example.ir/ashpazi/khoresh
 *     -> public/cache/pages/example.ir/ashpazi/khoresh/index.html
 */
final class PageCache
{
    public static function enabled(): bool
    {
        return Config::bool('cache.enabled', true);
    }

    /**
     * Write a rendered page.
     *
     * Written to a temporary file and renamed, because rename is atomic on
     * the same filesystem: a reader can never be served a half-written page.
     */
    public static function put(string $host, string $path, string $html): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $file = self::fileFor($host, $path);
        if ($file === null) {
            return false;
        }

        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $html, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }

        return true;
    }

    public static function forget(string $host, string $path): void
    {
        $file = self::fileFor($host, $path);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /** Drop a path and everything beneath it. */
    public static function forgetTree(string $host, string $path = ''): void
    {
        $directory = self::directoryFor($host, $path);
        if ($directory === null || !is_dir($directory)) {
            return;
        }

        self::removeDirectory($directory);
    }

    /** Clear the whole cache. Used after a settings or template change. */
    public static function flush(): int
    {
        $root = self::root();
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $removed += self::removeDirectory($root . '/' . $entry);
        }

        return $removed;
    }

    /**
     * Absolute path of the cache file for a URL path, or null when the path
     * is not safe to map onto the filesystem.
     */
    public static function fileFor(string $host, string $path): ?string
    {
        $directory = self::directoryFor($host, $path);

        return $directory === null ? null : $directory . '/index.html';
    }

    private static function directoryFor(string $host, string $path): ?string
    {
        $host = self::safeHost($host);
        if ($host === null) {
            return null;
        }

        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' ) {
                continue;
            }
            // A path that could escape the cache root is never written. This
            // is the check that makes writing attacker-influenced URLs safe.
            if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        $directory = self::root() . '/' . $host;

        return $segments === [] ? $directory : $directory . '/' . implode('/', $segments);
    }

    private static function safeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return preg_match('/^[a-z0-9.\-]{1,253}$/', $host) === 1 && !str_contains($host, '..')
            ? $host
            : null;
    }

    private static function root(): string
    {
        return rtrim(Config::string('cache.dir', dirname(__DIR__, 2) . '/public/cache/pages'), '/');
    }

    private static function removeDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
                $removed++;
            }
        }

        @rmdir($directory);

        return $removed;
    }
}
