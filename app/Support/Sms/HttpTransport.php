<?php
declare(strict_types=1);

namespace App\Support\Sms;

/**
 * A minimal HTTPS client for the SMS gateways, with short timeouts: a slow
 * gateway must not hold a PHP worker for long on a shared host.
 *
 * Tests substitute a fake by passing a callable to the senders instead.
 */
final class HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 8): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'the curl extension is not installed'];
        }

        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? curl_error($handle) : '';
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
    }
}
