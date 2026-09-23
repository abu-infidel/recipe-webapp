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
 *   php tools/admin-user.php you@example.com "Your Name" --generate
 *
 * Prompts for a password without echoing it. With --generate it makes a
 * strong one and prints it instead, for hosts without Terminal, where the
 * command is run once as a cPanel cron job and its output arrives by email.
 * Sign in and keep that password somewhere safe; delete the cron job.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Domain\AdminAuth;

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--generate'));
$generate = in_array('--generate', $argv, true);
$email = $args[0] ?? null;
$name  = $args[1] ?? 'Owner';

if ($email === null) {
    fwrite(STDERR, "Usage: php tools/admin-user.php <email> [name]\n");
    exit(1);
}

if (Database::value('SELECT 1 FROM admin_users WHERE email = :e', ['e' => mb_strtolower($email)]) !== null) {
    fwrite(STDERR, "An account already exists for {$email}.\n");
    exit(1);
}

if ($generate) {
    // 20 characters from an unambiguous alphabet: about 110 bits.
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} else {
    echo 'Password (min 12 characters): ';
    system('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
}

if (mb_strlen($password, 'UTF-8') < 12) {
    fwrite(STDERR, "Too short. Use at least 12 characters.\n");
    exit(1);
}

$id = AdminAuth::createUser($email, $name, $password, 'owner');

echo "Created admin user {$id} ({$email}).\n";
if ($generate) {
    echo "Password: {$password}\n";
}
echo 'Sign in at ' . \App\Core\Url::base() . "/admin\n";
