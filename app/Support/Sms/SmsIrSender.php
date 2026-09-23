<?php
declare(strict_types=1);

namespace App\Support\Sms;

/**
 * SMS.ir, REST API v1 "verify": a template defined in the SMS.ir panel with
 * one parameter (named CODE by default).
 *
 *   POST https://api.sms.ir/v1/send/verify
 *   X-API-KEY: …
 *   {"mobile": "09…", "templateId": 123456, "parameters": [{"name": "CODE", "value": "…"}]}
 *   → {"status": 1, "message": "…", "data": {...}}
 */
final class SmsIrSender implements SmsSender
{
    /** @var callable(string,string,array,?string,int):array */
    private $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly int $templateId,
        private readonly string $parameter = 'CODE',
        private readonly int $timeout = 8,
        ?callable $http = null,
        private readonly string $baseUrl = 'https://api.sms.ir/v1',
    ) {
        $this->http = $http ?? HttpTransport::request(...);
    }

    public function sendCode(string $local, string $code): SmsResult
    {
        if ($this->apiKey === '' || $this->templateId <= 0) {
            return SmsResult::failed('sms.ir: api_key and template_id must be set');
        }

        $body = json_encode([
            'mobile'     => $local,
            'templateId' => $this->templateId,
            'parameters' => [['name' => $this->parameter, 'value' => $code]],
        ]);

        $response = ($this->http)('POST', rtrim($this->baseUrl, '/') . '/send/verify', [
            'X-API-KEY'    => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $body, $this->timeout);

        $json = json_decode($response['body'], true);

        return (int) ($json['status'] ?? 0) === 1
            ? SmsResult::sent('sms.ir 1')
            : SmsResult::failed('sms.ir ' . ($json['status'] ?? $response['status']) . ': ' . mb_substr((string) ($json['message'] ?? $response['error']), 0, 200, 'UTF-8'));
    }

    public function name(): string
    {
        return 'smsir';
    }
}
