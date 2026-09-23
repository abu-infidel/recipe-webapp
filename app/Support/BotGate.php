<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;

/**
 * Decides whether a request is a reader, a welcome crawler, or a scraper.
 *
 * The host cannot absorb bulk crawling, but the site *wants* assistants to
 * know what it covers. The resolution is to make the summary free and the
 * bulk expensive: /llms.txt, the AI manifest and the sitemap are never gated,
 * while article pages are.
 *
 * Checks run cheapest first, and nothing here writes a cookie.
 */
final class BotGate
{
    public const ALLOW     = 'allow';
    public const CRAWLER   = 'crawler';
    public const CHALLENGE = 'challenge';
    public const BLOCK     = 'block';

    /** Paths that are always served, whatever else is true. */
    private const NEVER_GATED = [
        '/llms.txt',
        '/.well-known/ai-manifest.json',
        '/robots.txt',
        '/sitemap.xml',
        '/about-for-ai',
        '/manifest.webmanifest',
    ];

    /**
     * @return array{verdict:string, reason:string, retry_after:int}
     */
    public static function inspect(Request $request): array
    {
        if (!Config::bool('security.bot_gate.enabled', true)) {
            return self::verdict(self::ALLOW, 'gate disabled');
        }

        if (in_array($request->path, self::NEVER_GATED, true)) {
            return self::verdict(self::ALLOW, 'manifest path');
        }

        $ip = $request->ip;
        $userAgent = strtolower($request->userAgent);

        // 1. Already blocked.
        $blocked = self::activeBlock($ip);
        if ($blocked !== null) {
            return self::verdict(self::BLOCK, $blocked, 3600);
        }

        // 2. The honeypot. Hidden from readers and from screen readers, so
        //    only something following every href in the markup reaches it.
        if ($request->path === Config::string('security.bot_gate.honeypot_path')) {
            self::block($ip, 'honeypot');
            return self::verdict(self::BLOCK, 'honeypot', 3600);
        }

        // 3. Verified good crawlers get a relaxed budget. Verification is by
        //    reverse DNS, never by the user-agent string, which anyone can set.
        if (self::isVerifiedCrawler($request)) {
            return self::verdict(self::CRAWLER, 'verified crawler');
        }

        // 4. Obvious scraping tooling, claimed in the UA itself.
        foreach ((array) Config::get('security.bot_gate.blocked_agents', []) as $needle) {
            if ($needle !== '' && str_contains($userAgent, strtolower((string) $needle))) {
                return self::verdict(self::BLOCK, 'blocked agent: ' . $needle, 3600);
            }
        }

        // 5. A browser always sends an Accept header and a user-agent.
        if ($userAgent === '') {
            return self::verdict(self::BLOCK, 'no user-agent', 600);
        }
        if ($request->header('accept') === '' && $request->isGet()) {
            return self::verdict(self::CHALLENGE, 'no accept header');
        }

        // 6. Rate limits.
        $class = self::classFor($request);
        $limit = RateLimiter::check($ip, $class);

        if (!$limit['allowed']) {
            return self::verdict(self::CHALLENGE, "rate limit ({$class})", max(1, $limit['retry_after']));
        }

        // 7. The daily cap catches the politely-paced scraper that stays
        //    under every per-minute limit. It challenges rather than blocks,
        //    because Iranian mobile carriers put thousands of readers behind
        //    one address and a block would take out the whole carrier.
        if ($class === 'article' && !RateLimiter::countArticleRead($ip)) {
            return self::verdict(self::CHALLENGE, 'daily article cap', 60);
        }

        RateLimiter::sweep();

        return self::verdict(self::ALLOW, 'ok');
    }

    /**
     * Confirm a crawler by reverse DNS plus a forward lookup back to the same
     * address. A UA string alone proves nothing.
     *
     * The result is cached per hostname for a day, because a DNS round trip
     * on a page request is expensive.
     */
    public static function isVerifiedCrawler(Request $request): bool
    {
        $userAgent = $request->userAgent;
        $crawlers = (array) Config::get('security.bot_gate.verified_crawlers', []);

        $claimed = null;
        foreach ($crawlers as $name => $domains) {
            if (stripos($userAgent, (string) $name) !== false) {
                $claimed = ['name' => $name, 'domains' => (array) $domains];
                break;
            }
        }

        if ($claimed === null) {
            return false;
        }

        $cacheKey = 'crawler:' . Privacy::hashValue($request->ip . '|' . $claimed['name']);
        $cached = Ephemeral::get($cacheKey);

        if ($cached !== null) {
            return $cached === '1';
        }

        $verified = self::reverseForwardConfirm($request->ip, $claimed['domains']);
        Ephemeral::put($cacheKey, $verified ? '1' : '0', 86400);

        return $verified;
    }

    private static function reverseForwardConfirm(string $ip, array $domains): bool
    {
        $hostname = @gethostbyaddr($ip);
        if ($hostname === false || $hostname === $ip) {
            return false;
        }

        $matches = false;
        foreach ($domains as $domain) {
            if (str_ends_with($hostname, (string) $domain)) {
                $matches = true;
                break;
            }
        }

        if (!$matches) {
            return false;
        }

        // Forward-confirm: the hostname must resolve back to this address,
        // or a forged PTR record would be enough to impersonate Googlebot.
        $forward = @gethostbynamel($hostname);

        return is_array($forward) && in_array($ip, $forward, true);
    }

    /** Which rate-limit budget this request draws on. */
    private static function classFor(Request $request): string
    {
        $path = $request->path;

        if (str_starts_with($path, '/assets/') || str_starts_with($path, '/media/')) {
            return 'asset';
        }
        if ($path === '/api/beacon') {
            return 'beacon';
        }
        if (str_starts_with($path, '/api/')) {
            return 'api';
        }
        if ($path === '/search') {
            return 'search';
        }

        return 'article';
    }

    public static function block(string $ip, string $reason): void
    {
        $minutes = Config::int('security.bot_gate.block_minutes', 120);

        try {
            Database::run(
                'INSERT INTO blocklist (ip_hash, reason, blocked_until, hits)
                 VALUES (:hash, :reason, DATE_ADD(NOW(), INTERVAL :minutes MINUTE), 1)
                 ON DUPLICATE KEY UPDATE
                     hits = hits + 1,
                     reason = VALUES(reason),
                     blocked_until = DATE_ADD(NOW(), INTERVAL :minutes2 MINUTE)',
                [
                    'hash' => Privacy::hashIp($ip),
                    'reason' => mb_substr($reason, 0, 200, 'UTF-8'),
                    'minutes' => $minutes,
                    'minutes2' => $minutes,
                ]
            );
        } catch (\Throwable $e) {
            error_log('Could not record block: ' . $e->getMessage());
        }
    }

    private static function activeBlock(string $ip): ?string
    {
        try {
            $row = Database::first(
                'SELECT reason FROM blocklist WHERE ip_hash = :hash AND blocked_until > NOW() LIMIT 1',
                ['hash' => Privacy::hashIp($ip)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row === null ? null : (string) $row['reason'];
    }

    /**
     * @return array{verdict:string, reason:string, retry_after:int}
     */
    private static function verdict(string $verdict, string $reason, int $retryAfter = 0): array
    {
        return ['verdict' => $verdict, 'reason' => $reason, 'retry_after' => $retryAfter];
    }
}
