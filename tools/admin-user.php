<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Create the first admin account.
 *
 *   php tools/admin-user.php you@example.com "Your Name"
 *
 * Prompts for a password without echoing it.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Domain\AdminAuth;

$email = $argv[1] ?? null;
$name  = $argv[2] ?? 'Owner';

if ($email === null) {
    fwrite(STDERR, "Usage: php tools/admin-user.php <email> [name]\n");
    exit(1);
}

if (Database::value('SELECT 1 FROM admin_users WHERE email = :e', ['e' => mb_strtolower($email)]) !== null) {
    fwrite(STDERR, "An account already exists for {$email}.\n");
    exit(1);
}

echo 'Password (min 12 characters): ';
system('stty -echo 2>/dev/null');
$password = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

if (mb_strlen($password, 'UTF-8') < 12) {
    fwrite(STDERR, "Too short. Use at least 12 characters.\n");
    exit(1);
}

$id = AdminAuth::createUser($email, $name, $password, 'owner');

echo "Created admin user {$id} ({$email}).\n";
echo 'Sign in at ' . \App\Core\Url::base() . "/admin\n";
