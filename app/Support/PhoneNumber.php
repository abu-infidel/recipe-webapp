<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Iranian mobile numbers, and the only form in which one is ever stored.
 *
 * Only +98 9xx xxx xxxx is accepted. That is who the site is for, and it is
 * also the main defence against SMS pumping: fraudsters make sites text
 * premium-rate international numbers they profit from, and a domestic-only
 * rule removes the incentive entirely.
 *
 * The number itself is used once — to send the code — and never written
 * anywhere. What is stored is hash(), an HMAC under a secret pepper kept in
 * config.local.php. The whole Iranian mobile range is only about a billion
 * numbers, so a plain hash could be reversed by trying them all; the pepper
 * is what makes the stored value useless without the server's secret.
 */
final class PhoneNumber
{
    /**
     * The canonical +989xxxxxxxxx form, or null if this is not an Iranian
     * mobile number. Accepts Persian or Arabic digits, spaces, dashes,
     * brackets, and the 09…, 9…, 989…, 00989… and +989… spellings.
     */
    public static function normalize(string $input): ?string
    {
        $digits = PersianText::toAsciiDigits(trim($input));
        $digits = preg_replace('/[\s\-().\x{200C}\x{200E}\x{200F}]/u', '', $digits) ?? '';

        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^98(9\d{9})$/', $digits, $m) === 1
            || preg_match('/^0(9\d{9})$/', $digits, $m) === 1
            || preg_match('/^(9\d{9})$/', $digits, $m) === 1) {
            return '+98' . $m[1];
        }

        return null;
    }

    /** 09xxxxxxxxx, the form Iranian SMS gateways expect. */
    public static function local(string $canonical): string
    {
        return '0' . substr($canonical, 3);
    }

    /**
     * The stored identity of a number. Throws if no pepper is configured:
     * without one the hash would be reversible, so sign-in stays off.
     */
    public static function hash(string $canonical): string
    {
        return hash_hmac('sha256', 'phone|' . $canonical, self::pepper());
    }

    public static function isConfigured(): bool
    {
        return strlen(Config::string('security.phone_pepper')) >= 32;
    }

    private static function pepper(): string
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('security.phone_pepper is not set (32+ random characters in config.local.php).');
        }

        return Config::string('security.phone_pepper');
    }
}
