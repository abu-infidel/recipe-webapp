<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Support\PersianText;
use App\Support\PhoneNumber;
use App\Support\Privacy;
use App\Support\Settings;
use App\Support\Sms\SmsGateway;

/**
 * Contributor sign-in with a one-time code sent by SMS.
 *
 * What is stored: an HMAC of the phone number (never the number), a hash of
 * each code (never the code), and a hash of the session cookie (never the
 * cookie). A database copy is enough to see that accounts exist and nothing
 * more.
 *
 * What limits abuse, cheapest first: only Iranian mobile numbers; a proof of
 * work before a code is sent (checked by the controller); one code per number
 * per minute and a few per day; a cap per address per hour; a cap for the
 * whole site per hour, which bounds the SMS bill whatever an attacker does;
 * five guesses per code; codes that expire in two minutes.
 *
 * The session cookie is scoped to /account, so it is never sent with a
 * content page: those stay cookie-free and served from the static cache.
 */
final class ContributorAuth
{
    public const COOKIE = 'contrib';
    public const PATH = '/account';

    /** @var array<string,mixed>|null|false false = looked up, nobody signed in */
    private static array|null|false $current = null;

    /** Sign-in needs the switch on, a pepper, and a working SMS gateway. */
    public static function available(): bool
    {
        return Settings::bool('contributions.enabled', true)
            && PhoneNumber::isConfigured()
            && SmsGateway::isConfigured();
    }

    // ---------------------------------------------------------------- codes

    /**
     * Send a code. The caller has already checked the proof of work.
     *
     * @return array{ok:true, token:string, masked:string}|array{ok:false, error:string, wait?:int}
     */
    public static function requestCode(string $phoneInput, string $ip): array
    {
        if (!self::available()) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        $phone = PhoneNumber::normalize($phoneInput);
        if ($phone === null) {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }

        $phoneHash = PhoneNumber::hash($phone);
        $ipHash = Privacy::hashIp($ip);
        $now = time();

        $status = Database::value('SELECT status FROM contributors WHERE phone_hash = :h', ['h' => $phoneHash]);
        if ($status === 'suspended') {
            return ['ok' => false, 'error' => 'suspended'];
        }

        $last = (int) Database::value('SELECT MAX(created_at) FROM otp_requests WHERE phone_hash = :h', ['h' => $phoneHash], 0);
        $resend = Config::int('otp.resend_seconds', 60);
        if ($last > $now - $resend) {
            return ['ok' => false, 'error' => 'too_soon', 'wait' => $resend - ($now - $last)];
        }

        $counts = [
            'phone_limit' => [
                (int) Database::value('SELECT COUNT(*) FROM otp_requests WHERE phone_hash = :h AND created_at > :t', ['h' => $phoneHash, 't' => $now - 86400], 0),
                Config::int('otp.per_phone_per_day', 5),
            ],
            'ip_limit' => [
                (int) Database::value('SELECT COUNT(*) FROM otp_requests WHERE ip_hash = :h AND created_at > :t', ['h' => $ipHash, 't' => $now - 3600], 0),
                Config::int('otp.per_ip_per_hour', 10),
            ],
            // The site-wide ceiling: whatever an attacker does, this bounds
            // the SMS bill.
            'busy' => [
                (int) Database::value('SELECT COUNT(*) FROM otp_requests WHERE created_at > :t', ['t' => $now - 3600], 0),
                Config::int('otp.global_per_hour', 300),
            ],
        ];
        foreach ($counts as $error => [$count, $max]) {
            if ($count >= $max) {
                return ['ok' => false, 'error' => $error];
            }
        }

        $code = sprintf('%06d', random_int(0, 999999));
        $token = bin2hex(random_bytes(16));

        $id = Database::insert('otp_requests', [
            'phone_hash' => $phoneHash,
            'token_hash' => hash('sha256', $token),
            'code_hash'  => self::codeHash($token, $code),
            'ip_hash'    => $ipHash,
            'created_at' => $now,
            'expires_at' => $now + Config::int('otp.ttl_seconds', 120),
        ]);

        try {
            $result = SmsGateway::sender()->sendCode(PhoneNumber::local($phone), $code);
        } catch (\Throwable $e) {
            $result = \App\Support\Sms\SmsResult::failed($e->getMessage());
        }

        if (!$result->ok) {
            // Kept (as spent) so a failing gateway cannot be hammered, but
            // unusable: nobody received this code.
            Database::run('UPDATE otp_requests SET consumed = 1 WHERE id = :id', ['id' => $id]);
            error_log('SMS send failed: ' . $result->detail);
            return ['ok' => false, 'error' => 'send_failed'];
        }

        return ['ok' => true, 'token' => $token, 'masked' => self::mask($phone)];
    }

    /**
     * Check a code. On success the contributor exists (created on first
     * sign-in) and is returned.
     *
     * @return array{ok:true, contributor:array}|array{ok:false, error:string, remaining?:int}
     */
    public static function verifyCode(string $token, string $code): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return ['ok' => false, 'error' => 'expired'];
        }
        $code = preg_replace('/\D/', '', PersianText::toAsciiDigits($code)) ?? '';

        $row = Database::first(
            'SELECT * FROM otp_requests WHERE token_hash = :t AND consumed = 0 AND expires_at > :now',
            ['t' => hash('sha256', $token), 'now' => time()]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => 'expired'];
        }

        $max = Config::int('otp.max_attempts', 5);

        // Count the guess before checking it, atomically, so parallel guesses
        // cannot exceed the limit.
        $counted = Database::run(
            'UPDATE otp_requests SET attempts = attempts + 1 WHERE id = :id AND attempts < :max AND consumed = 0',
            ['id' => $row['id'], 'max' => $max]
        )->rowCount();
        if ($counted === 0) {
            return ['ok' => false, 'error' => 'expired'];
        }

        if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], self::codeHash($token, $code))) {
            $remaining = $max - (int) $row['attempts'] - 1;
            return $remaining > 0 ? ['ok' => false, 'error' => 'wrong_code', 'remaining' => $remaining] : ['ok' => false, 'error' => 'expired'];
        }

        // Single use, even against a racing duplicate submit.
        if (Database::run('UPDATE otp_requests SET consumed = 1 WHERE id = :id AND consumed = 0', ['id' => $row['id']])->rowCount() === 0) {
            return ['ok' => false, 'error' => 'expired'];
        }

        Database::run('INSERT IGNORE INTO contributors (phone_hash) VALUES (:h)', ['h' => $row['phone_hash']]);
        $contributor = Database::first('SELECT * FROM contributors WHERE phone_hash = :h', ['h' => $row['phone_hash']]);

        if ($contributor === null || $contributor['status'] !== 'active') {
            return ['ok' => false, 'error' => 'suspended'];
        }

        Database::run('UPDATE contributors SET last_login_at = NOW() WHERE id = :id', ['id' => $contributor['id']]);

        return ['ok' => true, 'contributor' => $contributor];
    }

    // ------------------------------------------------------------- sessions

    /** Create a session; returns the cookie value to set. */
    public static function startSession(array $contributor, Request $request): string
    {
        $value = bin2hex(random_bytes(32));

        Database::insert('contributor_sessions', [
            'id'             => hash('sha256', $value),
            'contributor_id' => (int) $contributor['id'],
            'ua_hash'        => hash('sha256', $request->userAgent),
            'expires_at'     => time() + Config::int('otp.session_days', 30) * 86400,
        ]);

        self::$current = null;

        return $value;
    }

    public static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => self::PATH,
            'secure'   => Config::string('site.scheme') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** The signed-in contributor, or null. */
    public static function current(Request $request): ?array
    {
        if (self::$current !== null) {
            return self::$current === false ? null : self::$current;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || preg_match('/^[0-9a-f]{64}$/', $cookie) !== 1) {
            self::$current = false;
            return null;
        }

        $row = Database::first(
            'SELECT s.id AS session_id, s.ua_hash, c.*
             FROM contributor_sessions s
             INNER JOIN contributors c ON c.id = s.contributor_id
             WHERE s.id = :id AND s.expires_at > :now',
            ['id' => hash('sha256', $cookie), 'now' => time()]
        );

        // Bound to the browser that signed in; not to the address, which
        // changes constantly on Iranian mobile networks.
        if ($row === null || $row['status'] !== 'active' || !hash_equals((string) $row['ua_hash'], hash('sha256', $request->userAgent))) {
            self::$current = false;
            return null;
        }

        Database::run('UPDATE contributor_sessions SET last_seen_at = NOW() WHERE id = :id', ['id' => $row['session_id']]);

        unset($row['ua_hash']);
        self::$current = $row;

        return $row;
    }

    public static function endSession(): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            Database::run('DELETE FROM contributor_sessions WHERE id = :id', ['id' => hash('sha256', $cookie)]);
        }
        self::setCookie('', time() - 3600);
        self::$current = false;
    }

    /** Sign a contributor out everywhere (used when suspending). */
    public static function endAllSessions(int $contributorId): void
    {
        Database::run('DELETE FROM contributor_sessions WHERE contributor_id = :id', ['id' => $contributorId]);
    }

    public static function csrfToken(): string
    {
        return hash_hmac('sha256', 'contrib-csrf|' . (string) ($_COOKIE[self::COOKIE] ?? ''), Config::string('security.app_key'));
    }

    public static function checkCsrf(Request $request): bool
    {
        $submitted = (string) ($request->input('_csrf') ?? '');

        return $submitted !== '' && ($_COOKIE[self::COOKIE] ?? '') !== '' && hash_equals(self::csrfToken(), $submitted);
    }

    /** Remove spent codes and dead sessions. Cheap; called now and then. */
    public static function sweep(): void
    {
        Database::run('DELETE FROM otp_requests WHERE created_at < :t', ['t' => time() - 86400]);
        Database::run('DELETE FROM contributor_sessions WHERE expires_at < :t', ['t' => time()]);
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$current = null;
    }

    private static function codeHash(string $token, string $code): string
    {
        return hash_hmac('sha256', 'otp|' . $token . '|' . $code, Config::string('security.app_key'));
    }

    /** 0912•••4567 — enough for the person to recognise their own number. */
    private static function mask(string $canonical): string
    {
        $local = PhoneNumber::local($canonical);

        return substr($local, 0, 4) . '•••' . substr($local, -4);
    }
}
