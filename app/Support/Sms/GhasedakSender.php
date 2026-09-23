<?php
declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Ghasedak (ghasedak.me), verification API with a panel-approved template
 * whose first parameter is the code.
 *
 *   POST https://api.ghasedak.me/v2/verification/send/simple
 *   apikey: …
 *   receptor=09…&type=1&template=NAME&param1=CODE
 *   → {"result": {"code": 200, "message": "success"}, "items": [...]}
 */
final class GhasedakSender implements SmsSender
{
    /** @var callable(string,string,array,?string,int):array */
    private $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $template,
        private readonly int $timeout = 8,
        ?callable $http = null,
        private readonly string $baseUrl = 'https://api.ghasedak.me/v2',
    ) {
        $this->http = $http ?? HttpTransport::request(...);
    }

    public function sendCode(string $local, string $code): SmsResult
    {
        if ($this->apiKey === '' || $this->template === '') {
            return SmsResult::failed('ghasedak: api_key and template must be set');
        }

        $response = ($this->http)('POST', rtrim($this->baseUrl, '/') . '/verification/send/simple', [
            'apikey'       => $this->apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ], http_build_query(['receptor' => $local, 'type' => 1, 'template' => $this->template, 'param1' => $code]), $this->timeout);

        $json = json_decode($response['body'], true);
        $status = (int) ($json['result']['code'] ?? $response['status']);

        return $status === 200
            ? SmsResult::sent('ghasedak 200')
            : SmsResult::failed('ghasedak ' . $status . ': ' . mb_substr((string) ($json['result']['message'] ?? $response['error']), 0, 200, 'UTF-8'));
    }

    public function name(): string
    {
        return 'ghasedak';
    }
}
