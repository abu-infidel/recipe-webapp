<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/**
 * Short-lived key/value storage with an expiry.
 *
 * For values that must vanish on their own — replay nonces, challenge passes,
 * cached crawler verdicts. A small table rather than APCu or Redis because
 * shared hosting guarantees neither, and rather than `settings` because
 * `settings` has no expiry and would grow for ever.
 */
final class Ephemeral
{
    public static function put(string $key, string $value, int $ttlSeconds): void
    {
        Database::run(
            'INSERT INTO ephemeral (k, v, expires_at) VALUES (:k, :v, :exp)
             ON DUPLICATE KEY UPDATE v = VALUES(v), expires_at = VALUES(expires_at)',
            ['k' => self::key($key), 'v' => mb_substr($value, 0, 1000, 'UTF-8'), 'exp' => time() + max(1, $ttlSeconds)]
        );

        self::sweep();
    }

    public static function get(string $key): ?string
    {
        $value = Database::value(
            'SELECT v FROM ephemeral WHERE k = :k AND expires_at >= :now',
            ['k' => self::key($key), 'now' => time()]
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * Store a value only if no live one exists. Returns false when the key is
     * already held.
     *
     * One statement, so two racing requests cannot both succeed — which is
     * the whole point when the key is a replay nonce. It relies on MySQL's
     * affected-row count for an upsert: 1 inserted, 2 replaced an expired row,
     * 0 left a live row untouched.
     */
    public static function add(string $key, string $value, int $ttlSeconds): bool
    {
        $now = time();

        $affected = Database::run(
            'INSERT INTO ephemeral (k, v, expires_at) VALUES (:k, :v, :exp)
             ON DUPLICATE KEY UPDATE
                 v = IF(expires_at < :now1, VALUES(v), v),
                 expires_at = IF(expires_at < :now2, VALUES(expires_at), expires_at)',
            [
                'k'    => self::key($key),
                'v'    => mb_substr($value, 0, 1000, 'UTF-8'),
                'exp'  => $now + max(1, $ttlSeconds),
                'now1' => $now,
                'now2' => $now,
            ]
        )->rowCount();

        self::sweep();

        return $affected > 0;
    }

    public static function delete(string $key): void
    {
        Database::delete('ephemeral', 'k = :k', ['k' => self::key($key)]);
    }

    /**
     * Remove expired rows. Opportunistic rather than scheduled, because cron
     * is not guaranteed on shared hosting.
     */
    public static function sweep(int $probability = 100): void
    {
        if (random_int(1, max(1, $probability)) !== 1) {
            return;
        }

        try {
            Database::run('DELETE FROM ephemeral WHERE expires_at < :now LIMIT 1000', ['now' => time()]);
        } catch (\Throwable $e) {
            // Cleanup failing must never fail the request that triggered it.
        }
    }

    /** Keys longer than the column are hashed rather than truncated into collisions. */
    private static function key(string $key): string
    {
        return strlen($key) <= 190 ? $key : substr($key, 0, 120) . ':' . hash('sha256', $key);
    }
}
