<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Dates in the Persian (Jalali) calendar, which is what Persian readers use.
 *
 * intl is present on the host (CloudLinux ships it); without it this falls
 * back to an ISO date rather than failing.
 */
final class Jalali
{
    public static function format(?string $datetime, string $pattern = 'd MMMM y'): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '';
        }

        if (!class_exists(\IntlDateFormatter::class)) {
            return date('Y-m-d', $timestamp);
        }

        $formatter = new \IntlDateFormatter(
            'fa_IR@calendar=persian',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            Config::string('site.timezone', 'Asia/Tehran'),
            \IntlDateFormatter::TRADITIONAL,
            $pattern
        );

        return (string) $formatter->format($timestamp);
    }
}
