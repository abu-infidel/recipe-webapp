<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Support\Privacy;

/**
 * Admin authentication.
 *
 * The one place on this site that sets a cookie, and it is strictly necessary
 * and scoped to the admin path. Public pages set none at all.
 *
 * The session cookie holds a random value; only its SHA-256 hash is stored,
 * so a database leak does not hand over live sessions.
 */
final class AdminAuth
{
    public const COOKIE = 'admin_session';

    private static ?array $user = null;

    /**
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function attempt(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');

        $user = Database::first(
            'SELECT * FROM admin_users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        // Hash even when the account does not exist, so response time does not
        // reveal which emails are registered.
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$aaaaaaaaaaaaaaaa$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $valid = password_verify($password, $hash);

        if ($user === null) {
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }

        if (!$user['is_active']) {
            return ['ok' => false, 'error' => 'This account is disabled.'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again later.'];
        }

        if (!$valid) {
            self::recordFailure((int) $user['id'], (int) $user['failed_attempts']);
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }

        // Opportunistically upgrade a hash made with older parameters.
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            Database::update('admin_users', [
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ], 'id = :id', ['id' => (int) $user['id']]);
        }

        Database::update('admin_users', [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $user['id']]);

        return ['ok' => true, 'user' => $user];
    }

    /** Create a session and return the cookie value to set. */
    public static function startSession(array $user, Request $request): string
    {
        $value = bin2hex(random_bytes(32));
        $minutes = Config::int('security.admin.session_minutes', 240);

        Database::insert('admin_sessions', [
            'id'         => hash('sha256', $value),
            'user_id'    => (int) $user['id'],
            'ip_hash'    => Privacy::hashIp($request->ip),
            'ua_hash'    => hash('sha256', $request->userAgent),
            'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
        ]);

        self::audit((int) $user['id'], 'login', null, null, $request);

        return $value;
    }

    /** The signed-in user, or null. */
    public static function user(Request $request): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        $session = Database::first(
            'SELECT s.*, u.email, u.name, u.role, u.is_active
             FROM admin_sessions s
             INNER JOIN admin_users u ON u.id = s.user_id
             WHERE s.id = :id AND s.expires_at > NOW()
             LIMIT 1',
            ['id' => hash('sha256', $cookie)]
        );

        if ($session === null || !$session['is_active']) {
            return null;
        }

        // A session is bound to the browser that created it. The IP is not
        // checked: Iranian mobile addresses change constantly and pinning to
        // one would sign the user out every few minutes.
        if (!hash_equals((string) $session['ua_hash'], hash('sha256', $request->userAgent))) {
            self::destroySession($cookie);
            return null;
        }

        Database::update('admin_sessions', [
            'last_seen_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $session['id']]);

        return self::$user = [
            'id'    => (int) $session['user_id'],
            'email' => $session['email'],
            'name'  => $session['name'],
            'role'  => $session['role'],
        ];
    }

    public static function destroySession(string $cookieValue): void
    {
        Database::delete('admin_sessions', 'id = :id', ['id' => hash('sha256', $cookieValue)]);
    }

    /** A CSRF token bound to the session, so it cannot be reused elsewhere. */
    public static function csrfToken(): string
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';

        return hash_hmac('sha256', 'csrf', (string) $cookie . Config::string('security.app_key'));
    }

    public static function checkCsrf(Request $request): bool
    {
        $submitted = (string) ($request->input('_csrf') ?? $request->header('x-csrf-token'));

        return $submitted !== '' && hash_equals(self::csrfToken(), $submitted);
    }

    public static function audit(
        ?int $userId,
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?Request $request = null,
        array $meta = [],
    ): void {
        try {
            Database::insert('admin_audit_log', [
                'user_id'   => $userId,
                'action'    => mb_substr($action, 0, 80, 'UTF-8'),
                'entity'    => $entity,
                'entity_id' => $entityId,
                'meta'      => $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null),
                'ip_hash'   => $request !== null ? Privacy::hashIp($request->ip) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('Could not write audit entry: ' . $e->getMessage());
        }
    }

    public static function createUser(string $email, string $name, string $password, string $role = 'owner'): int
    {
        return Database::insert('admin_users', [
            'email'         => mb_strtolower(trim($email), 'UTF-8'),
            'name'          => $name,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'role'          => in_array($role, ['owner', 'editor'], true) ? $role : 'editor',
        ]);
    }

    private static function recordFailure(int $userId, int $attempts): void
    {
        $attempts++;
        $max = Config::int('security.admin.max_login_tries', 5);

        Database::update('admin_users', [
            'failed_attempts' => $attempts,
            // Lock rather than throttle: five wrong passwords is not a typo.
            'locked_until'    => $attempts >= $max ? date('Y-m-d H:i:s', time() + 900) : null,
        ], 'id = :id', ['id' => $userId]);
    }
}
