<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Privacy;

/**
 * The proof-of-work interstitial shown to a client that tripped the rate
 * limiter.
 *
 * A real reader sees a page that says "one moment" and solves in well under a
 * second; a scraper pays that cost on every request, which is what makes bulk
 * extraction uneconomic.
 *
 * No cookie is involved. The solved challenge is recorded server-side against
 * the daily-salted IP hash, so nothing is stored on the reader's device and
 * nothing identifies them beyond the hash that expires tonight.
 */
final class ChallengeController
{
    private const PASS_MINUTES = 30;

    public static function show(Request $request, string $reason = '', int $retryAfter = 30): Response
    {
        $nonce = bin2hex(random_bytes(16));
        $difficulty = Config::int('security.bot_gate.pow_difficulty', 16);

        // The nonce is remembered so a solution cannot be replayed or
        // precomputed for an arbitrary string.
        Database::run(
            'INSERT INTO settings (name, value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()',
            ['name' => 'pow:' . $nonce, 'value' => (string) time()]
        );

        $html = View::page('public.challenge', 'public.layout', [
            'nonce'      => $nonce,
            'difficulty' => $difficulty,
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

    /** Verify a submitted solution and lift the limit for a while. */
    public static function verify(Request $request): Response
    {
        $nonce = (string) ($request->input('nonce') ?? '');
        $solution = (string) ($request->input('solution') ?? '');

        if ($nonce === '' || $solution === '' || !ctype_xdigit($nonce)) {
            return Response::json(['ok' => false, 'error' => 'bad_request'], 400)->noCache();
        }

        $issued = Database::value('SELECT value FROM settings WHERE name = :name', ['name' => 'pow:' . $nonce]);
        if ($issued === null) {
            return Response::json(['ok' => false, 'error' => 'unknown_nonce'], 400)->noCache();
        }

        // A challenge is single-use and short-lived.
        Database::delete('settings', 'name = :name', ['name' => 'pow:' . $nonce]);

        if (time() - (int) $issued > 300) {
            return Response::json(['ok' => false, 'error' => 'expired'], 400)->noCache();
        }

        if (!self::isValidSolution($nonce, $solution, Config::int('security.bot_gate.pow_difficulty', 16))) {
            return Response::json(['ok' => false, 'error' => 'invalid'], 400)->noCache();
        }

        Database::run(
            'INSERT INTO settings (name, value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()',
            [
                'name'  => 'powpass:' . Privacy::hashIp($request->ip),
                'value' => (string) (time() + self::PASS_MINUTES * 60),
            ]
        );

        return Response::json(['ok' => true])->noCache();
    }

    /** True while this client holds a valid pass. */
    public static function hasPass(string $ip): bool
    {
        $until = Database::value(
            'SELECT value FROM settings WHERE name = :name',
            ['name' => 'powpass:' . Privacy::hashIp($ip)]
        );

        return $until !== null && (int) $until > time();
    }

    /**
     * The solution must make sha256(nonce + solution) start with $bits zero
     * bits. Checked on the raw digest rather than the hex string so the
     * difficulty is a real bit count.
     */
    private static function isValidSolution(string $nonce, string $solution, int $bits): bool
    {
        if (strlen($solution) > 64) {
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
        if ($remainder === 0) {
            return true;
        }

        return (ord($digest[$fullBytes]) >> (8 - $remainder)) === 0;
    }
}
