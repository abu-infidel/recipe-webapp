<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;

/**
 * Fixed-window rate limiting, keyed on a daily-salted hash of the client IP.
 *
 * Deliberately not a token bucket: a fixed window is one row and one
 * upsert per request, which is what a small shared host can afford. The
 * cost of the edge case (a client getting up to 2x the limit across a window
 * boundary) is not worth a more expensive algorithm here.
 *
 * Separate classes so that loading a page full of images cannot exhaust the
 * budget for reading articles.
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public static function check(string $ip, string $class): array
    {
        if (!Config::bool('security.rate_limit.enabled', true)) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $limit = Config::int("security.rate_limit.{$class}.limit", 60);
        $window = Config::int("security.rate_limit.{$class}.window", 60);

        if ($limit <= 0 || $window <= 0) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $now = time();
        $windowStart = $now - ($now % $window);
        $key = Privacy::bucketKey($ip, $class);

        try {
            // One statement: insert the window, or bump it, or reset it if the
            // stored row belongs to an older window.
            Database::run(
                'INSERT INTO rate_limit_buckets (bucket_key, window_start, hits, expires_at)
                 VALUES (:key, :start, 1, :expires)
                 ON DUPLICATE KEY UPDATE
                     hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                     window_start = VALUES(window_start),
                     expires_at = VALUES(expires_at)',
                ['key' => $key, 'start' => $windowStart, 'expires' => $windowStart + $window]
            );

            $hits = (int) Database::value(
                'SELECT hits FROM rate_limit_buckets WHERE bucket_key = :key',
                ['key' => $key],
                1
            );
        } catch (\Throwable $e) {
            // A limiter that fails closed would take the site down over a
            // transient database error. Log and let the request through.
            error_log('Rate limiter unavailable: ' . $e->getMessage());
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        return [
            'allowed'     => $hits <= $limit,
            'remaining'   => max(0, $limit - $hits),
            'retry_after' => $hits <= $limit ? 0 : ($windowStart + $window) - $now,
        ];
    }

    /**
     * The daily cap on article-page requests from one address.
     *
     * Catches the scraper that stays under every per-minute limit by pacing
     * itself. Exceeding it triggers the proof-of-work challenge rather than a
     * block, because a single Iranian carrier address can front thousands of
     * genuine readers.
     */
    public static function countArticleRead(string $ip): bool
    {
        $cap = Config::int('security.rate_limit.daily_article_cap', 120);
        if ($cap <= 0) {
            return true;
        }

        $key = Privacy::bucketKey($ip, 'article-daily');
        $midnight = strtotime('tomorrow midnight') ?: (time() + 86400);

        try {
            Database::run(
                'INSERT INTO rate_limit_buckets (bucket_key, window_start, hits, expires_at)
                 VALUES (:key, :start, 1, :expires)
                 ON DUPLICATE KEY UPDATE
                     hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                     window_start = VALUES(window_start),
                     expires_at = VALUES(expires_at)',
                ['key' => $key, 'start' => strtotime('today midnight') ?: 0, 'expires' => $midnight]
            );

            return (int) Database::value(
                'SELECT hits FROM rate_limit_buckets WHERE bucket_key = :key',
                ['key' => $key],
                1
            ) <= $cap;
        } catch (\Throwable $e) {
            error_log('Daily cap unavailable: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Delete expired rows. Called opportunistically (roughly 1 request in
     * 200) rather than on a cron, because cron is not guaranteed on shared
     * hosting and this table would otherwise grow without bound.
     */
    public static function sweep(int $probability = 200): int
    {
        if (random_int(1, max(1, $probability)) !== 1) {
            return 0;
        }

        try {
            return Database::run(
                'DELETE FROM rate_limit_buckets WHERE expires_at < :now LIMIT 1000',
                ['now' => time()]
            )->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
