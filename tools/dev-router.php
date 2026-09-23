<?php
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
/**
 * Router for PHP's built-in server, which has no .htaccess.
 *
 *   php -S 127.0.0.1:8080 -t public tools/dev-router.php
 *
 * Returning false hands the request back to the server so it can serve a real
 * file; everything else goes to the front controller, the way LiteSpeed's
 * rewrite does in production.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . rawurldecode($path);   // the repository layout, dev only

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';
