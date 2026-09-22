<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuration, loaded once from app/config.php with app/config.local.php
 * merged over the top. The local file holds credentials and is gitignored.
 *
 * Values are addressed with dot notation: Config::get('db.host').
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $baseFile, ?string $localFile = null): void
    {
        $values = require $baseFile;

        if ($localFile !== null && is_file($localFile)) {
            $values = self::mergeDeep($values, require $localFile);
        }

        self::$values = $values;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new \RuntimeException('Config::load() must be called before Config::get().');
        }

        $value = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Override a value for the lifetime of the request. Used by tests and by
     * the admin settings screen, which writes through to the database.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &self::$values;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    public static function all(): array
    {
        return self::$values;
    }

    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
