<?php
declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Kavenegar (kavenegar.com), "verify lookup": a template approved in the
 * Kavenegar panel with a %token% placeholder. Templated OTP messages are
 * delivered on the priority route and are not blocked by the
 * advertising-SMS filter.
 *
 *   GET https://api.kavenegar.com/v1/{api_key}/verify/lookup.json
 *       ?receptor=09…&token=CODE&template=NAME
 *   → {"return": {"status": 200, "message": "…"}, "entries": [...]}
 */
final class KavenegarSender implements SmsSender
{
    /** @var callable(string,string,array,?string,int):array */
    private $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $template,
        private readonly int $timeout = 8,
        ?callable $http = null,
        private readonly string $baseUrl = 'https://api.kavenegar.com/v1',
    ) {
        $this->http = $http ?? HttpTransport::request(...);
    }

    public function sendCode(string $local, string $code): SmsResult
    {
        if ($this->apiKey === '' || $this->template === '') {
            return SmsResult::failed('kavenegar: api_key and template must be set');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->apiKey) . '/verify/lookup.json?'
            . http_build_query(['receptor' => $local, 'token' => $code, 'template' => $this->template]);

        $response = ($this->http)('GET', $url, ['Accept' => 'application/json'], null, $this->timeout);
        $json = json_decode($response['body'], true);
        $status = (int) ($json['return']['status'] ?? $response['status']);

        return $status === 200
            ? SmsResult::sent('kavenegar 200')
            // The API key is part of the URL; never let it reach a log.
            : SmsResult::failed('kavenegar ' . $status . ': ' . mb_substr((string) ($json['return']['message'] ?? $response['error']), 0, 200, 'UTF-8'));
    }

    public function name(): string
    {
        return 'kavenegar';
    }
}
