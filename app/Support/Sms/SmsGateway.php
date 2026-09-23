<?php
declare(strict_types=1);

namespace App\Support\Sms;

use App\Core\Config;

/**
 * The configured SMS sender. Switching provider is a config change:
 *
 *   'sms' => ['driver' => 'kavenegar', 'kavenegar' => ['api_key' => '…', 'template' => '…']]
 */
final class SmsGateway
{
    public const DRIVERS = ['kavenegar', 'smsir', 'ghasedak', 'log'];

    private static ?SmsSender $override = null;

    /** @throws \RuntimeException when nothing usable is configured */
    public static function sender(): SmsSender
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $driver = Config::string('sms.driver', 'kavenegar');
        $timeout = max(2, Config::int('sms.timeout', 8));

        return match ($driver) {
            'kavenegar' => new KavenegarSender(Config::string('sms.kavenegar.api_key'), Config::string('sms.kavenegar.template'), $timeout),
            'smsir'     => new SmsIrSender(Config::string('sms.smsir.api_key'), Config::int('sms.smsir.template_id'), Config::string('sms.smsir.parameter', 'CODE'), $timeout),
            'ghasedak'  => new GhasedakSender(Config::string('sms.ghasedak.api_key'), Config::string('sms.ghasedak.template'), $timeout),
            // The log holds numbers and live codes in plain text: never in production.
            'log'       => Config::bool('debug') ? new LogSender() : throw new \RuntimeException('The "log" SMS driver only works with debug on.'),
            default     => throw new \RuntimeException("Unknown SMS driver \"{$driver}\"."),
        };
    }

    /** Whether sign-in can work: a known driver with its credentials filled in. */
    public static function isConfigured(): bool
    {
        return match (Config::string('sms.driver', 'kavenegar')) {
            'kavenegar' => Config::string('sms.kavenegar.api_key') !== '' && Config::string('sms.kavenegar.template') !== '',
            'smsir'     => Config::string('sms.smsir.api_key') !== '' && Config::int('sms.smsir.template_id') > 0,
            'ghasedak'  => Config::string('sms.ghasedak.api_key') !== '' && Config::string('sms.ghasedak.template') !== '',
            'log'       => Config::bool('debug'),
            default     => false,
        };
    }

    /** Tests substitute a recording sender. */
    public static function use(?SmsSender $sender): void
    {
        self::$override = $sender;
    }
}
