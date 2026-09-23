<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * Authenticates the research worker running on the VPS abroad.
 *
 * Three independent checks, because this endpoint can write content:
 *   1. a bearer token, stored only as a SHA-256 hash
 *   2. an HMAC over the exact request body, with a timestamp and a nonce, so
 *      a captured request cannot be replayed or altered in flight
 *   3. an optional IP allowlist
 */
final class WorkerAuth
{
    /**
     * @return array{ok:bool, error?:string, token_id?:int}
     */
    public static function verify(Request $request, string $body): array
    {
        if (!Config::bool('worker.enabled', true)) {
            return ['ok' => false, 'error' => 'worker_api_disabled'];
        }

        $allowlist = (array) Config::get('worker.ip_allowlist', []);
        if ($allowlist !== [] && !in_array($request->ip, $allowlist, true)) {
            return ['ok' => false, 'error' => 'ip_not_allowed'];
        }

        $token = self::bearerToken($request);
        if ($token === null) {
            return ['ok' => false, 'error' => 'missing_token'];
        }

        $row = Database::first(
            'SELECT id, scopes FROM api_tokens WHERE token_hash = :hash AND is_active = 1 LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        $timestamp = (int) $request->header('x-worker-timestamp');
        $nonce = $request->header('x-worker-nonce');
        $signature = $request->header('x-worker-signature');

        if ($timestamp === 0 || $nonce === '' || $signature === '') {
            return ['ok' => false, 'error' => 'missing_signature_headers'];
        }

        $skew = Config::int('worker.clock_skew', 300);
        if (abs(time() - $timestamp) > $skew) {
            return ['ok' => false, 'error' => 'timestamp_out_of_range'];
        }

        $secret = Config::string('worker.hmac_secret');
        if ($secret === '' || $secret === 'CHANGE-ME') {
            return ['ok' => false, 'error' => 'server_hmac_not_configured'];
        }

        $expected = hash_hmac(
            'sha256',
            $request->method . "\n" . $request->path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body),
            $secret
        );

        // Constant-time: a timing side channel here would leak the signature
        // one byte at a time.
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'error' => 'bad_signature'];
        }

        if (!self::consumeNonce($nonce, $timestamp, $skew)) {
            return ['ok' => false, 'error' => 'nonce_replayed'];
        }

        Database::update('api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $row['id']]);

        return ['ok' => true, 'token_id' => (int) $row['id']];
    }

    /**
     * Record a nonce, refusing one already seen.
     *
     * Ephemeral::add is a single atomic statement, so two requests racing with
     * the same nonce cannot both win. Nonces only need to be remembered for as
     * long as a timestamp would still be accepted.
     */
    private static function consumeNonce(string $nonce, int $timestamp, int $skew): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $nonce)) {
            return false;
        }

        return Ephemeral::add('wnonce:' . $nonce, (string) $timestamp, $skew * 2);
    }

    private static function bearerToken(Request $request): ?string
    {
        $header = $request->header('authorization');

        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /** Create a token. The plaintext is returned once and never stored. */
    public static function issueToken(string $name, string $scopes = 'worker'): string
    {
        $token = 'wk_' . bin2hex(random_bytes(24));

        Database::insert('api_tokens', [
            'name'       => $name,
            'token_hash' => hash('sha256', $token),
            'scopes'     => $scopes,
        ]);

        return $token;
    }
}
