<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the web front controller and every CLI tool.
 * Returns nothing; sets up autoloading, config, errors and timezone.
 */

require __DIR__ . '/autoload.php';

use App\Core\Config;

Config::load(__DIR__ . '/config.php', __DIR__ . '/config.local.php');

date_default_timezone_set(Config::string('site.timezone', 'Asia/Tehran'));
mb_internal_encoding('UTF-8');

if (Config::bool('debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
