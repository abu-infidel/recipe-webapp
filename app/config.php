<?php
declare(strict_types=1);

/**
 * Default configuration. Committed to git — contains no secrets.
 *
 * Copy app/config.local.php.example to app/config.local.php and put the real
 * database credentials and keys there. That file is gitignored and overrides
 * anything below.
 */
return [
    'site' => [
        // Shown in the header, the <title> suffix and the AI manifest.
        'name_fa'    => 'دانشنامه دستور پخت و راهنما',
        'name_en'    => 'Persian Recipes & Guides',
        'tagline_fa' => 'دستورهای پخت و راهنماهای گام‌به‌گام، با منابع',
        'domain'     => 'localhost:8080',
        'scheme'     => 'http',
        'locale'     => 'fa-IR',
        'direction'  => 'rtl',
        'timezone'   => 'Asia/Tehran',
    ],

    'routing' => [
        // 'path'      -> /f/cooking/stews          (launch mode)
        // 'subdomain' -> cooking.example.ir/stews  (flip when subdomains exist)
        // The router understands both regardless; this only decides which URL
        // is canonical and therefore what links and <link rel=canonical> emit.
        'mode'            => 'path',
        'field_prefix'    => 'f',
        'article_prefix'  => 'a',
        // Maps a hostname to a root field slug when mode is 'subdomain'.
        'subdomain_map'   => [],
        'reserved_hosts'  => ['www', 'admin', 'api', 'static', 'media'],
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'recipes',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'cache' => [
        // Static HTML cache. A hit never starts PHP — .htaccess serves the
        // file directly. This is what lets shared hosting carry real traffic.
        'enabled' => true,
        'dir'     => __DIR__ . '/../public/cache/pages',
        'ttl'     => 86400 * 7,
    ],

    'content' => [
        // The sidebar table of contents. In RTL the start side is the right,
        // which is where Persian Wikipedia puts it and what readers expect.
        // Set to 'left' if you want it mirrored.
        'toc_side'            => 'right',
        'toc_min_headings'    => 3,
        'related_limit'       => 8,
        'autolink_per_target' => 1,   // link each target once per article
        'excerpt_length'      => 200,
    ],

    'search' => [
        'results_per_page' => 20,
        'snippet_radius'   => 90,
        'min_token_length' => 2,
        // Zone weights for scoring. Title matches dominate, as readers expect.
        'weights' => ['title' => 12.0, 'summary' => 4.0, 'heading' => 3.0, 'body' => 1.0, 'alias' => 8.0],
    ],

    'privacy' => [
        // No visitor cookies anywhere on the public site. The admin session
        // cookie is strictly necessary and scoped to the admin path only.
        'visitor_cookies' => false,
        'ip_salt_rotates' => true,   // hashed IPs stop being linkable each day
    ],

    'ads' => [
        // 'none' | 'house' | 'network'
        // 'network' loads third-party JavaScript that sets tracking cookies
        // and will fail during an international blackout. It stays off until
        // you deliberately turn it on.
        'default_provider' => 'house',
        'network' => [
            'enabled'        => false,
            'name'           => '',
            'script_origins' => [],   // added to CSP only while enabled
            'consent_banner' => true,
        ],
        'slots' => ['article-top', 'article-mid', 'article-bottom', 'sidebar', 'field-footer'],
    ],

    'security' => [
        'rate_limit' => [
            'enabled' => true,
            // requests / seconds, per hashed IP, per class
            'article' => ['limit' => 60,  'window' => 60],
            'search'  => ['limit' => 20,  'window' => 60],
            'asset'   => ['limit' => 300, 'window' => 60],
            'api'     => ['limit' => 30,  'window' => 60],
            // A human never reads 120 distinct articles in a day. A scraper
            // does it in two minutes.
            'daily_article_cap' => 120,
        ],
        'bot_gate' => [
            'enabled'         => true,
            'honeypot_path'   => '/archive/all-entries',
            'pow_difficulty'  => 16,    // leading zero bits; ~50ms on a phone
            'block_minutes'   => 120,
            // Verified by reverse-DNS + forward confirm, never by UA alone.
            'verified_crawlers' => [
                'Googlebot'      => ['.googlebot.com', '.google.com'],
                'bingbot'        => ['.search.msn.com'],
                'GPTBot'         => ['.openai.com'],
                'OAI-SearchBot'  => ['.openai.com'],
                'ClaudeBot'      => ['.anthropic.com'],
                'PerplexityBot'  => ['.perplexity.ai'],
                'Applebot'       => ['.applebot.apple.com'],
                'YandexBot'      => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            ],
            // Obvious scraping tooling. Blocked outright.
            'blocked_agents' => [
                'python-requests', 'scrapy', 'httpx', 'aiohttp', 'go-http-client',
                'java/', 'libwww-perl', 'wget', 'curl/', 'node-fetch', 'axios/',
                'httrack', 'wpbot', 'semrushbot', 'ahrefsbot', 'mj12bot',
                'dotbot', 'dataforseo', 'bytespider', 'petalbot', 'serpstatbot',
            ],
        ],
        'admin' => [
            'path'            => '/admin',
            'session_minutes' => 240,
            'require_totp'    => false,   // turn on once you have an authenticator
            'ip_allowlist'    => [],      // empty = any IP
            'max_login_tries' => 5,
        ],
    ],

    'worker' => [
        // The VPS abroad pulls jobs from here. The site itself never makes an
        // outbound request, so it needs no internet access at all.
        'enabled'          => true,
        'lease_seconds'    => 900,
        'max_attempts'     => 3,
        'clock_skew'       => 300,   // HMAC timestamp tolerance
        'ip_allowlist'     => [],    // set to the VPS address before going live
    ],

    'offline' => [
        'service_worker'     => true,
        'precache_articles'  => 40,
        'single_file_export' => true,
    ],

    'debug' => false,
];
