<?php
declare(strict_types=1);

// Command-line only. If this file is ever reachable over HTTP (a manual
// upload under public_html), it must do nothing at all.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Validates a theme against the UI contract.
 *
 *   php tools/theme-check.php                    the configured theme
 *   php tools/theme-check.php mytheme            public/themes/mytheme
 *   php tools/theme-check.php ./some/folder      a theme folder anywhere
 *                                                (its name is the folder name)
 *   php tools/theme-check.php mytheme --render out/
 *                                                also write each page type,
 *                                                rendered with sample data
 *
 * Needs no database: every page type is rendered against the contract's
 * sample models. Exit status is 0 when the theme has no errors.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Paths;
use App\Core\Template\Theme;
use App\Core\Template\ThemeChecker;
use App\Http\UiContract;

$args = array_slice($argv, 1);
$renderTo = null;
if (($i = array_search('--render', $args, true)) !== false) {
    $renderTo = $args[$i + 1] ?? null;
    array_splice($args, $i, 2);
    if ($renderTo === null) {
        fwrite(STDERR, "--render needs a folder.\n");
        exit(2);
    }
}

$target = $args[0] ?? Config::string('ui.theme', 'default');

if (str_contains($target, '/') || is_dir($target) && !Theme::isValidName($target)) {
    $dir = rtrim((string) realpath($target), '/');
    $name = basename($dir);
} else {
    $dir = Paths::themes() . '/' . $target;
    $name = $target;
}

$report = ThemeChecker::check($dir, $name);

$color = static fn(string $code, string $text) => stream_isatty(STDOUT) ? "\033[{$code}m{$text}\033[0m" : $text;

echo "Theme \"{$name}\" ({$dir})\n\n";

foreach ($report['errors'] as $error) {
    echo '  ' . $color('31', 'error') . "    {$error}\n";
}
foreach ($report['warnings'] as $warning) {
    echo '  ' . $color('33', 'warning') . "  {$warning}\n";
}
if ($report['rendered'] !== []) {
    echo "\n  Rendered with sample data: ";
    echo implode(', ', array_map(static fn($t, $b) => "{$t} (" . number_format($b / 1024, 1) . ' KB)', array_keys($report['rendered']), $report['rendered'])) . "\n";
}

if ($renderTo !== null && $report['ok']) {
    if (!is_dir($renderTo) && !mkdir($renderTo, 0o775, true)) {
        fwrite(STDERR, "Cannot create {$renderTo}.\n");
        exit(2);
    }
    $theme = Theme::fromDirectory($dir, $name);
    foreach (Theme::PAGE_TYPES as $type) {
        file_put_contents(rtrim($renderTo, '/') . "/{$type}.html", $theme->render($type, UiContract::fixture($type, $theme)));
    }
    echo "\n  Wrote " . count(Theme::PAGE_TYPES) . " pages to {$renderTo}. Asset URLs are root-relative; serve them from the site to see styles.\n";
}

echo "\n" . ($report['ok']
    ? $color('32', 'OK') . ' — ' . count($report['warnings']) . " warning(s).\n"
    : $color('31', 'FAILED') . ' — ' . count($report['errors']) . ' error(s), ' . count($report['warnings']) . " warning(s).\n");

exit($report['ok'] ? 0 : 1);
