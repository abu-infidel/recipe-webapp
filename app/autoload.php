<?php
declare(strict_types=1);

/**
 * PSR-4 autoloader for the App\ namespace.
 *
 * Hand-rolled on purpose: cPanel shared hosting does not reliably offer
 * Composer, and this project has no third-party PHP dependencies. Keeping it
 * this way means deployment is "copy the files up" and nothing else.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
