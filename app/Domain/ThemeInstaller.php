<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\PageCache;
use App\Core\Paths;
use App\Core\Template\Theme;
use App\Core\Template\ThemeChecker;
use App\Support\Settings;

/**
 * Installs, activates and removes themes from the admin, without git.
 *
 * An uploaded zip is unpacked entry by entry into a staging folder outside
 * the web root — never with extractTo(), which trusts the archive's paths —
 * and checked there. Only a theme with no errors is moved into
 * public/themes/, and the move is a rename within one filesystem, so a
 * reader never sees a half-copied theme.
 *
 * The folder is web-served, which is why the checker refuses every file type
 * but a short list: a .php or .htaccess file there would be executed or obeyed
 * by the web server.
 */
final class ThemeInstaller
{
    private const MAX_ENTRIES = 400;

    /** @return list<array{name:string, title:string, version:string, description:string, active:bool, builtin:bool, api:mixed}> */
    public static function all(): array
    {
        $active = Theme::active()->name;
        $themes = [];

        foreach (glob(Paths::themes() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!Theme::isValidName($name)) {
                continue;
            }
            $manifest = json_decode((string) @file_get_contents($dir . '/theme.json'), true);
            $manifest = is_array($manifest) ? $manifest : [];

            $themes[] = [
                'name'        => $name,
                'title'       => (string) ($manifest['title'] ?? $name),
                'version'     => (string) ($manifest['version'] ?? ''),
                'description' => (string) ($manifest['description'] ?? ''),
                'api'         => $manifest['api'] ?? null,
                'active'      => $name === $active,
                'builtin'     => $name === 'default',
            ];
        }

        usort($themes, static fn(array $a, array $b) => [!$a['active'], $a['name']] <=> [!$b['active'], $b['name']]);

        return $themes;
    }

    /**
     * @return array{ok:bool, name:?string, errors:list<string>, warnings:list<string>}
     */
    public static function installZip(string $zipFile, bool $replace): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return self::fail('This server\'s PHP has no zip extension. Upload the theme folder to public_html/themes/ with the cPanel File Manager instead.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return self::fail('That file is not a readable zip archive.');
        }

        try {
            return self::installFromArchive($zip, $replace);
        } finally {
            $zip->close();
        }
    }

    private static function installFromArchive(\ZipArchive $zip, bool $replace): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return self::fail('The archive has ' . $zip->numFiles . ' entries; a theme needs far fewer (limit ' . self::MAX_ENTRIES . ').');
        }

        // Pass 1: read the directory, refusing anything unsafe before a byte
        // is written.
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $path = (string) ($stat['name'] ?? '');

            // Archive tools' litter, not part of the theme.
            if (str_starts_with($path, '__MACOSX/') || basename($path) === '.DS_Store') {
                continue;
            }
            if (str_ends_with($path, '/')) {
                continue;
            }
            if (str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, ':')
                || in_array('..', explode('/', $path), true)) {
                return self::fail("Unsafe path in archive: {$path}");
            }
            if ($zip->getExternalAttributesIndex($i, $system, $attributes)
                && $system === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0xF000) === 0xA000) {
                return self::fail("The archive contains a symbolic link: {$path}");
            }

            $total += (int) $stat['size'];
            if ($total > ThemeChecker::MAX_TOTAL_BYTES) {
                return self::fail('The theme unpacks to more than ' . intdiv(ThemeChecker::MAX_TOTAL_BYTES, 1048576) . ' MB.');
            }

            $entries[$path] = $i;
        }

        // The theme is either at the top of the archive or inside one folder.
        $prefix = null;
        if (isset($entries['theme.json'])) {
            $prefix = '';
        } else {
            $tops = array_unique(array_map(static fn(string $p) => explode('/', $p)[0], array_keys($entries)));
            if (count($tops) === 1 && isset($entries[$tops[0] . '/theme.json'])) {
                $prefix = $tops[0] . '/';
            }
        }
        if ($prefix === null) {
            return self::fail('No theme.json at the top of the archive (or inside a single top-level folder).');
        }

        $manifest = json_decode((string) $zip->getFromIndex($entries[$prefix . 'theme.json']), true);
        $name = is_array($manifest) ? (string) ($manifest['name'] ?? '') : '';
        if (!Theme::isValidName($name)) {
            return self::fail('theme.json needs a "name" of lowercase letters, digits, - and _ (it becomes the folder name).');
        }
        if ($name === 'default') {
            return self::fail('"default" ships with the site\'s code and is replaced on every deploy. Give the theme another name.');
        }
        $target = Paths::themes() . '/' . $name;
        if (is_dir($target) && !$replace) {
            return self::fail("A theme called \"{$name}\" is already installed. Tick \"replace\" to overwrite it.", $name);
        }

        // Pass 2: write into staging, outside the web root.
        $staging = sys_get_temp_dir() . '/theme-upload-' . bin2hex(random_bytes(6));
        $dir = $staging . '/' . $name;

        try {
            foreach ($entries as $path => $index) {
                if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                    continue;
                }
                $relative = substr($path, strlen($prefix));
                foreach (explode('/', $relative) as $segment) {
                    if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-.]*$/', $segment) !== 1) {
                        return self::fail("File name not allowed: {$relative}", $name);
                    }
                }
                $file = $dir . '/' . $relative;
                if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0o755, true)) {
                    return self::fail('Could not unpack the archive on this server.', $name);
                }
                $contents = $zip->getFromIndex($index);
                if ($contents === false || file_put_contents($file, $contents) === false) {
                    return self::fail("Could not unpack {$relative}.", $name);
                }
            }

            $report = ThemeChecker::check($dir, $name);
            if (!$report['ok']) {
                return ['ok' => false, 'name' => $name, 'errors' => $report['errors'], 'warnings' => $report['warnings']];
            }

            self::moveIntoPlace($dir, $target);

            // Replacing the live theme changes every page.
            if ($name === Theme::active()->name) {
                PageCache::flush();
            }

            return ['ok' => true, 'name' => $name, 'errors' => [], 'warnings' => $report['warnings']];
        } finally {
            self::remove($staging);
        }
    }

    /**
     * @return array{ok:bool, errors:list<string>, warnings:list<string>}
     */
    public static function activate(string $name): array
    {
        if (!Theme::isValidName($name) || !is_dir(Paths::themes() . '/' . $name)) {
            return ['ok' => false, 'errors' => ["No theme called \"{$name}\"."], 'warnings' => []];
        }

        $report = ThemeChecker::check(Paths::themes() . '/' . $name, $name);
        if (!$report['ok']) {
            return ['ok' => false, 'errors' => $report['errors'], 'warnings' => $report['warnings']];
        }

        Settings::set('ui.theme', $name);
        Theme::use(null);
        PageCache::flush();

        return ['ok' => true, 'errors' => [], 'warnings' => $report['warnings']];
    }

    public static function delete(string $name): ?string
    {
        if (!Theme::isValidName($name) || !is_dir(Paths::themes() . '/' . $name)) {
            return "No theme called \"{$name}\".";
        }
        if ($name === 'default') {
            return 'The default theme ships with the site and is the fallback when another theme breaks; it cannot be removed.';
        }
        if ($name === Theme::active()->name) {
            return 'That theme is live. Activate another one first.';
        }

        self::remove(Paths::themes() . '/' . $name);

        return null;
    }

    /**
     * Copy into a hidden sibling, then swap with renames. Names starting with
     * a dot are not valid theme names, so a half-copied folder can never load.
     */
    private static function moveIntoPlace(string $from, string $target): void
    {
        $suffix = bin2hex(random_bytes(4));
        $incoming = dirname($target) . '/.incoming-' . basename($target) . '-' . $suffix;
        $outgoing = dirname($target) . '/.outgoing-' . basename($target) . '-' . $suffix;

        self::copy($from, $incoming);

        if (is_dir($target) && !rename($target, $outgoing)) {
            self::remove($incoming);
            throw new \RuntimeException('Could not replace the existing theme folder.');
        }
        if (!rename($incoming, $target)) {
            if (is_dir($outgoing)) {
                rename($outgoing, $target);
            }
            self::remove($incoming);
            throw new \RuntimeException('Could not move the theme into place.');
        }
        self::remove($outgoing);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0o755, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($dest, 0o755) : copy($item->getPathname(), $dest);
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function fail(string $message, ?string $name = null): array
    {
        return ['ok' => false, 'name' => $name, 'errors' => [$message], 'warnings' => []];
    }
}
