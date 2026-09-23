<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Ephemeral;
use App\Support\Privacy;

/**
 * The proof-of-work interstitial shown to a client that tripped the rate
 * limiter.
 *
 * A real reader waits well under a second; a scraper pays that cost on every
 * request, which is what makes bulk extraction uneconomic.
 *
 * Issuing a challenge writes nothing. The nonce is signed and carries its own
 * issue time and the client's hashed address, so it can be verified without
 * having been stored. Only a correctly *solved* challenge causes a write, which
 * means an attacker cannot grow the database just by being challenged.
 *
 * No cookie is involved: the pass is recorded against the daily-salted IP hash.
 */
final class ChallengeController
{
    private const PASS_SECONDS  = 1800;
    private const NONCE_SECONDS = 300;

    public static function show(Request $request, string $reason = '', int $retryAfter = 30): Response
    {
        $html = View::page('public.challenge', 'public.layout', [
            'nonce'      => self::issueNonce($request->ip),
            'difficulty' => Config::int('security.bot_gate.pow_difficulty', 16),
            'retryAfter' => $retryAfter,
            'pageTitle'  => 'یک لحظه…',
            'bodyClass'  => 'page-challenge',
            'noIndex'    => true,
            'scripts'    => ['/assets/js/challenge.js'],
        ]);

        return Response::html($html, 429)
            ->withHeader('Retry-After', (string) max(1, $retryAfter))
            ->noCache();
    }

    public static function verify(Request $request): Response
    {
        $nonce = (string) ($request->input('nonce') ?? '');
        $solution = (string) ($request->input('solution') ?? '');

        if (!self::isAuthentic($nonce, $request->ip)) {
            return Response::json(['ok' => false, 'error' => 'invalid_nonce'], 400)->noCache();
        }

        if (!self::isValidSolution($nonce, $solution, Config::int('security.bot_gate.pow_difficulty', 16))) {
            return Response::json(['ok' => false, 'error' => 'invalid'], 400)->noCache();
        }

        // Single use. add() is atomic, so the same solved nonce presented twice
        // at once still only counts once.
        if (!Ephemeral::add('powused:' . sha1($nonce), '1', self::NONCE_SECONDS * 2)) {
            return Response::json(['ok' => false, 'error' => 'replayed'], 400)->noCache();
        }

        Ephemeral::put('powpass:' . Privacy::hashIp($request->ip), '1', self::PASS_SECONDS);

        return Response::json(['ok' => true])->noCache();
    }

    /** True while this client holds a valid pass. */
    public static function hasPass(string $ip): bool
    {
        return Ephemeral::get('powpass:' . Privacy::hashIp($ip)) !== null;
    }

    /**
     * "<issued>.<random>.<signature>", signed over the issue time, the random
     * part and the client's hashed address. Bound to the address so a solved
     * nonce cannot be farmed out and redeemed from elsewhere.
     */
    public static function issueNonce(string $ip, ?int $now = null): string
    {
        $payload = ($now ?? time()) . '.' . bin2hex(random_bytes(8));

        return $payload . '.' . self::sign($payload, $ip);
    }

    public static function isAuthentic(string $nonce, string $ip, ?int $now = null): bool
    {
        if (preg_match('/^(\d{10})\.([0-9a-f]{16})\.([0-9a-f]{32})$/', $nonce, $parts) !== 1) {
            return false;
        }

        $age = ($now ?? time()) - (int) $parts[1];
        if ($age < 0 || $age > self::NONCE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($parts[1] . '.' . $parts[2], $ip), $parts[3]);
    }

    /**
     * sha256(nonce + solution) must start with $bits zero bits, checked on the
     * raw digest so the difficulty is a real bit count.
     */
    public static function isValidSolution(string $nonce, string $solution, int $bits): bool
    {
        if ($solution === '' || strlen($solution) > 64) {
            return false;
        }

        $digest = hash('sha256', $nonce . $solution, true);

        $fullBytes = intdiv($bits, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($digest[$i] !== "\x00") {
                return false;
            }
        }

        $remainder = $bits % 8;

        return $remainder === 0 || (ord($digest[$fullBytes]) >> (8 - $remainder)) === 0;
    }

    private static function sign(string $payload, string $ip): string
    {
        return substr(
            hash_hmac('sha256', $payload . '|' . Privacy::hashIp($ip), 'pow|' . Config::string('security.app_key')),
            0,
            32
        );
    }
}
