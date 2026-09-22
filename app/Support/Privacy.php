<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Turns identifying values into hashes that stop being identifying.
 *
 * Every table that records an IP stores only hash(daily_salt + ip). The salt
 * is derived from the app key and today's date, so yesterday's rows cannot be
 * matched to today's visitor even with the database in hand, and nothing on
 * the public site ever sets a cookie.
 */
final class Privacy
{
    /**
     * Today's salt. Rotating it daily is what makes the stored hashes
     * non-linkable over time; a fixed salt would make them a persistent
     * pseudonymous identifier, which is exactly what we are avoiding.
     */
    public static function dailySalt(): string
    {
        $key = Config::string('security.app_key');

        if ($key === '' || $key === 'CHANGE-ME') {
            // A missing key must not silently degrade into an empty salt.
            // Derived from the database name so it is at least stable per
            // install, and loudly wrong in the logs.
            error_log('security.app_key is not set — rate limiting is using a weak fallback salt.');
            $key = 'fallback:' . Config::string('db.name');
        }

        if (!Config::bool('privacy.ip_salt_rotates', true)) {
            return hash('sha256', $key . '|static');
        }

        return hash('sha256', $key . '|' . gmdate('Y-m-d'));
    }

    /** A non-reversible, non-linkable handle for an IP address. */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::dailySalt());
    }

    /** A bucket key that separates rate-limit classes from each other. */
    public static function bucketKey(string $ip, string $class): string
    {
        return hash_hmac('sha256', $class . '|' . $ip, self::dailySalt());
    }

    public static function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, self::dailySalt());
    }
}
