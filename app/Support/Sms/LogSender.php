<?php
declare(strict_types=1);

namespace App\Support\Sms;

use App\Core\Paths;

/**
 * Development only: writes the code to var/sms-outbox.log instead of
 * sending it. SmsGateway refuses it outside debug mode, because the log
 * holds phone numbers and live codes in plain text.
 */
final class LogSender implements SmsSender
{
    public function __construct(private readonly ?string $file = null) {}

    public function sendCode(string $local, string $code): SmsResult
    {
        $file = $this->file ?? Paths::root() . '/var/sms-outbox.log';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0o700, true);
        }

        $line = sprintf("%s\t%s\t%s\n", date('c'), $local, $code);

        return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false
            ? SmsResult::failed("could not write {$file}")
            : SmsResult::sent('logged');
    }

    public function name(): string
    {
        return 'log';
    }
}
