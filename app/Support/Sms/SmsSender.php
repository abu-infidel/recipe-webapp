<?php
declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Sends a sign-in code. One implementation per gateway; SmsGateway picks
 * the configured one.
 *
 * This is the only outbound request the site makes, and only on the sign-in
 * path. Every supported gateway is domestic, so sign-in keeps working when
 * international routes are cut.
 */
interface SmsSender
{
    /**
     * @param string $local  the number as 09xxxxxxxxx
     * @param string $code   the digits to deliver
     */
    public function sendCode(string $local, string $code): SmsResult;

    /** Short name for logs and the admin panel. */
    public function name(): string;
}
