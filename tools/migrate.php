<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Apply pending SQL migrations.
 *
 *   php tools/migrate.php            apply everything pending
 *   php tools/migrate.php --status   show what is applied and what is not
 *   php tools/migrate.php --fresh    DROP every table, then reapply (dev only)
 *
 * The same code is reachable from the admin panel, because cPanel shared
 * hosting often has Terminal disabled and a CLI-only migrator would strand you.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\Migrator;

$argvFlags = array_slice($argv, 1);
$status = in_array('--status', $argvFlags, true);
$fresh  = in_array('--fresh', $argvFlags, true);

$dir = __DIR__ . '/../db/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "No migration files in {$dir}\n");
    exit(1);
}

try {
    $pdo = Database::connect();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\033[31m" . $e->getMessage() . "\033[0m\n");
    fwrite(STDERR, "Check the 'db' section of app/config.local.php.\n");
    exit(1);
}

if ($fresh) {
    if (!Config::bool('debug') && (getenv('ALLOW_FRESH') !== '1')) {
        fwrite(STDERR, "--fresh drops every table. Refusing outside debug mode.\n");
        fwrite(STDERR, "Set debug=true in config.local.php, or ALLOW_FRESH=1, if you are sure.\n");
        exit(1);
    }
    fwrite(STDOUT, "Dropping all tables...\n");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (Database::column('SHOW TABLES') as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

if ($status) {
    foreach (Migrator::status() as $migration) {
        printf("  %s  %s\n", $migration['applied'] ? "\033[32mapplied\033[0m" : "\033[33mpending\033[0m", $migration['file']);
    }
    exit(0);
}

$result = Migrator::applyPending(static function (string $file): void {
    printf("  applying %s ...\n", $file);
});

if ($result['failed'] !== null) {
    fwrite(STDERR, "\033[31m  {$result['failed']} failed:\033[0m {$result['error']}\n");
    exit(1);
}

$ran = count($result['applied']);
echo $ran === 0 ? "  Nothing to do — schema is current.\n" : "  {$ran} migration(s) applied.\n";
