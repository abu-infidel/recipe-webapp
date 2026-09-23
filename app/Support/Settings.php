<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;

/**
 * The few values the admin can change at runtime, stored in `settings`.
 *
 * Everything else lives in the config files. A stored value overrides the
 * config value of the same key; an absent one falls through to it, so a fresh
 * install behaves exactly as configured.
 *
 * The whole table is read once per request — it holds a handful of rows —
 * and a database that cannot be reached simply means config values apply.
 * That matters for the CLI tools and the theme checker, which run without
 * one.
 */
final class Settings
{
    /** Keys that may be stored, with the type each is coerced to. */
    public const KEYS = [
        'ui.theme'                        => 'string',
        'contributions.enabled'           => 'bool',
        'contributions.judge_can_publish' => 'bool',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::load();

        return array_key_exists($key, $values) ? $values[$key] : Config::get($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        if (!isset(self::KEYS[$key])) {
            throw new \InvalidArgumentException("\"{$key}\" is not a runtime setting.");
        }

        $value = self::coerce($key, $value);

        Database::run(
            'INSERT INTO settings (name, value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['name' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE)]
        );

        self::$values = null;
    }

    /** Remove a stored override so the config value applies again. */
    public static function forget(string $key): void
    {
        Database::run('DELETE FROM settings WHERE name = :name', ['name' => $key]);
        self::$values = null;
    }

    public static function reset(): void
    {
        self::$values = null;
    }

    private static function load(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        self::$values = [];

        try {
            $rows = Database::pairs(
                'SELECT name, value FROM settings WHERE name IN (' . implode(',', array_fill(0, count(self::KEYS), '?')) . ')',
                array_keys(self::KEYS)
            );
        } catch (\Throwable) {
            return self::$values;
        }

        foreach ($rows as $name => $json) {
            $decoded = json_decode((string) $json, true);
            if ($decoded !== null || $json === 'null') {
                self::$values[$name] = self::coerce($name, $decoded);
            }
        }

        return self::$values;
    }

    private static function coerce(string $key, mixed $value): mixed
    {
        return match (self::KEYS[$key] ?? 'string') {
            'bool'  => (bool) $value,
            'int'   => (int) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
