<?php
declare(strict_types=1);

namespace App\Support\Sms;

final class SmsResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $detail,
    ) {}

    public static function sent(string $detail = ''): self
    {
        return new self(true, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(false, $detail);
    }
}
