<?php
declare(strict_types=1);

/**
 * Checks this server can actually run the site.
 *
 *   php tools/preflight.php
 *
 * Run it after deploying, before pointing anyone at the site. It is also
 * reachable from the admin panel, because cPanel shared hosting often has
 * Terminal disabled.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$checks = [];
$failed = 0;

function check(string $name, bool $ok, string $detail = '', bool $fatal = true): void
{
    global $checks, $failed;

    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fatal' => $fatal];
    if (!$ok && $fatal) {
        $failed++;
    }
}

// --- PHP -------------------------------------------------------------------

check('PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION);

foreach (['pdo_mysql', 'mbstring', 'intl', 'dom', 'json', 'openssl'] as $extension) {
    check("extension: {$extension}", extension_loaded($extension));
}

check(
    'extension: gd or imagick',
    extension_loaded('gd') || extension_loaded('imagick'),
    'needed to resize uploaded and generated images',
    false
);

check(
    'Argon2id password hashing',
    defined('PASSWORD_ARGON2ID'),
    'falls back to bcrypt if missing',
    false
);

// --- configuration ---------------------------------------------------------

check(
    'app/config.local.php exists',
    is_file(__DIR__ . '/../app/config.local.php'),
    'copy config.local.php.example and fill it in'
);

check(
    'security.app_key is set',
    !in_array(Config::string('security.app_key'), ['', 'CHANGE-ME'], true),
    'generate with: php -r "echo bin2hex(random_bytes(32));"'
);

check(
    'worker.hmac_secret is set',
    !in_array(Config::string('worker.hmac_secret'), ['', 'CHANGE-ME'], true),
    'must match SITE_HMAC_SECRET in worker/.env',
    false
);

check(
    'debug is off',
    !Config::bool('debug'),
    'debug mode prints stack traces to visitors',
    false
);

check(
    'site.domain is not a placeholder',
    !in_array(Config::string('site.domain'), ['', 'example.ir', 'localhost:8080'], true),
    Config::string('site.domain'),
    false
);

// --- database --------------------------------------------------------------

try {
    Database::connect();
    check('database connects', true, Config::string('db.name'));

    $version = (string) Database::value('SELECT VERSION()');
    check('MariaDB version', true, $version, false);

    $tables = (int) Database::value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db',
        ['db' => Config::string('db.name')],
        0
    );
    check('schema is installed', $tables >= 20, "{$tables} tables — run: php tools/migrate.php");

    // The timezone bug this catches is silent and confusing: stored timestamps
    // compare wrongly against PHP's clock by exactly the UTC offset.
    $drift = abs(time() - (int) strtotime((string) Database::value('SELECT NOW()')));
    check('database clock matches PHP', $drift <= 5, "{$drift} seconds apart");

    check(
        'an admin account exists',
        (int) Database::value('SELECT COUNT(*) FROM admin_users', [], 0) > 0,
        'create one with: php tools/admin-user.php you@example.com'
    );
} catch (\Throwable $e) {
    check('database connects', false, $e->getMessage());
}

// --- filesystem ------------------------------------------------------------

foreach ([
    'public/cache/pages' => 'the static page cache',
    'public/media'       => 'uploaded and generated images',
] as $path => $purpose) {
    $full = __DIR__ . '/../' . $path;
    check("{$path} is writable", is_dir($full) && is_writable($full), $purpose);
}

check(
    'app/ is outside the web root',
    !is_file(__DIR__ . '/../public/config.php'),
    'application code must never be reachable over HTTP',
    false
);

// --- self-containment ------------------------------------------------------
// The site must load nothing from a foreign origin, or a blackout that cuts
// international routes would break pages that are otherwise fine.

$fonts = glob(__DIR__ . '/../public/assets/fonts/*.woff2') ?: [];
check('Persian webfont is self-hosted', count($fonts) >= 1, count($fonts) . ' file(s)');

$external = [];
foreach ((glob(__DIR__ . '/../app/Views/**/*.php') ?: []) as $view) {
    $contents = (string) file_get_contents($view);
    if (preg_match('#(src|href)=["\']https?://#i', $contents, $match)) {
        $external[] = basename($view);
    }
}
check(
    'no external origins in templates',
    $external === [],
    $external === [] ? 'nothing loads from another server' : implode(', ', $external)
);

// --- report ----------------------------------------------------------------

echo "\n";
foreach ($checks as $entry) {
    $mark = $entry['ok'] ? "\033[32m  ok  \033[0m" : ($entry['fatal'] ? "\033[31m FAIL \033[0m" : "\033[33m warn \033[0m");
    printf("%s %-36s %s\n", $mark, $entry['name'], $entry['detail']);
}
echo "\n";

if ($failed > 0) {
    printf("\033[31m%d check(s) failed.\033[0m The site will not work correctly until they pass.\n", $failed);
    exit(1);
}

echo "\033[32mAll required checks passed.\033[0m\n";
