<?php
declare(strict_types=1);

/**
 * Issue an API token for the research worker.
 *
 *   php tools/worker-token.php "vps-paris"
 *
 * The plaintext token is printed once and never stored — only its SHA-256
 * hash goes in the database. Put it in the worker's .env as SITE_TOKEN.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Support\WorkerAuth;

$name = $argv[1] ?? 'research-worker';
$token = WorkerAuth::issueToken($name);

echo "Token for \"{$name}\":\n\n  {$token}\n\n";
echo "Store it in worker/.env as SITE_TOKEN. It cannot be shown again.\n";
