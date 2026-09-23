<?php
declare(strict_types=1);

namespace App\Core\Template;

use App\Http\UiContract;

/**
 * Checks a theme before it goes live.
 *
 * Errors are things that would break the site or its guarantees: a missing
 * template, a layout without the core head, a resource loaded from another
 * origin, an inline handler the CSP would silently block, a file type the
 * web server would execute. A theme with any error is not installed or
 * activated.
 *
 * Warnings are things a careful author would want to know: physical
 * left/right CSS on an RTL site, heavy assets, script that reaches for
 * cookies or the admin.
 *
 * Every page type is rendered against the contract's fixtures, so a template
 * that parses but misplaces {{page.head}} inside a section that never renders
 * is caught too.
 *
 * Used by tools/theme-check.php and by the admin Themes screen.
 */
final class ThemeChecker
{
    /** File types a theme may contain. Anything else — .php above all — is refused. */
    public const ALLOWED_EXTENSIONS = ['mustache', 'css', 'js', 'json', 'woff2', 'png', 'webp', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'txt'];

    public const MAX_TOTAL_BYTES = 5 * 1024 * 1024;
    private const HEAVY_ASSET_BYTES = 100 * 1024;

    /** XML namespaces are identifiers, not resources; they never load. */
    private const NAMESPACE_URL = '#^https?://www\.w3\.org/(2000/svg|1999/xlink|1999/xhtml|1998/Math/MathML|XML/1998/namespace)#';

    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string,int> */
    private array $rendered = [];

    /**
     * @return array{ok:bool, errors:list<string>, warnings:list<string>, rendered:array<string,int>}
     */
    public static function check(string $dir, string $name): array
    {
        $checker = new self();
        $checker->run($dir, $name);

        return [
            'ok'       => $checker->errors === [],
            'errors'   => $checker->errors,
            'warnings' => $checker->warnings,
            'rendered' => $checker->rendered,
        ];
    }

    private function run(string $dir, string $name): void
    {
        if (!is_dir($dir)) {
            $this->errors[] = "No theme folder at {$dir}.";
            return;
        }

        $this->checkFiles($dir);

        try {
            $theme = Theme::fromDirectory($dir, $name);
        } catch (TemplateError $e) {
            $this->errors[] = $e->getMessage();
            return;
        }

        $this->checkManifest($theme);
        $this->checkTemplates($theme);

        if ($this->errors === []) {
            $this->renderEveryPage($theme);
        }
    }

    // ------------------------------------------------------------------ files

    private function checkFiles(string $dir): void
    {
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');

            if ($file->isLink()) {
                $this->errors[] = "{$relative}: symbolic links are not allowed.";
                continue;
            }
            if ($file->isDir()) {
                continue;
            }
            if (str_starts_with($file->getFilename(), '.')) {
                $this->errors[] = "{$relative}: hidden files are not allowed (an .htaccess here would reconfigure the web server).";
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $this->errors[] = "{$relative}: .{$extension} files are not allowed in a theme. Allowed: " . implode(', ', self::ALLOWED_EXTENSIONS) . '.';
                continue;
            }

            $size = (int) $file->getSize();
            $total += $size;

            if (in_array($extension, ['css', 'js'], true) && $size > self::HEAVY_ASSET_BYTES) {
                $this->warnings[] = sprintf('%s is %d KB. Most readers are on slow mobile connections; keep CSS and JS small.', $relative, intdiv($size, 1024));
            }

            $source = in_array($extension, ['mustache', 'css', 'js', 'svg'], true)
                ? (string) file_get_contents($file->getPathname())
                : '';

            match ($extension) {
                'mustache' => $this->scanTemplate($relative, $source),
                'css'      => $this->scanCss($relative, $source),
                'js'       => $this->scanScript($relative, $source),
                'svg'      => $this->scanSvg($relative, $source),
                default    => null,
            };
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            $this->errors[] = sprintf('The theme is %.1f MB; the limit is %d MB.', $total / 1048576, intdiv(self::MAX_TOTAL_BYTES, 1048576));
        }
    }

    private function scanTemplate(string $file, string $source): void
    {
        // Comments may mention anything.
        $code = $this->blank($source, ['/\{\{!.*?\}\}/s', '/<!--.*?-->/s']);

        foreach ($this->foreignLoads($code) as [$line, $url]) {
            $this->errors[] = "{$file}:{$line}: loads {$url} from another origin. Everything must be served from this site.";
        }

        if (preg_match_all('/<[a-zA-Z][^>]*?\s(on[a-z]+)\s*=/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$attr, $offset]) {
                $this->errors[] = "{$file}:" . $this->line($code, $offset) . ": inline {$attr}= handler. The CSP blocks these; attach behaviour from the theme's JS.";
            }
        }

        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $script) {
                $attrs = $script[1][0];
                $body = trim($script[2][0]);
                $line = $this->line($code, $script[0][1]);

                if (preg_match('/\btype\s*=\s*["\']?application\/(ld\+)?json/i', $attrs)) {
                    continue;   // a data island, not code
                }
                if ($body !== '') {
                    $this->errors[] = "{$file}:{$line}: inline <script>. The CSP blocks it; put the code in a .js file listed in theme.json.";
                } elseif (preg_match('/\bsrc\s*=/i', $attrs)) {
                    $this->warnings[] = "{$file}:{$line}: <script src> in a template. List scripts in theme.json instead, so they are fingerprinted and saved for offline reading.";
                }
            }
        }

        if (preg_match_all('/\b(?:href|src|action|formaction)\s*=\s*["\']?\s*javascript:/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $offset]) {
                $this->errors[] = "{$file}:" . $this->line($code, $offset) . ': javascript: URL.';
            }
        }

        if (preg_match_all('/<(title|meta\s+name="description"|link\s+rel="canonical")/i', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$tag, $offset]) {
                $this->warnings[] = "{$file}:" . $this->line($code, $offset) . ": <{$tag}> is already emitted by {{page.head}}; a second one confuses search engines.";
            }
        }
    }

    private function scanCss(string $file, string $source): void
    {
        $code = $this->blank($source, ['#/\*.*?\*/#s']);

        foreach ($this->foreignLoads($code) as [$line, $url]) {
            $this->errors[] = "{$file}:{$line}: loads {$url} from another origin. Fonts and images must be served from this site.";
        }

        $physical = '/(?<![\w-])(margin-left|margin-right|padding-left|padding-right|border-left|border-right|(?<!inset-)left|(?<!inset-)right)\s*:|(?:float|text-align|clear)\s*:\s*(left|right)\b/';
        // A line marked /* physical */ is deliberate: geometric centring with
        // left: 50% and translate(-50%) is direction-independent, and a
        // logical property there would flip to `right` and break it.
        $sourceLines = explode("\n", $source);
        $hits = [];
        if (preg_match_all($physical, $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $line = $this->line($code, $hit[1]);
                if (!str_contains($sourceLines[$line - 1] ?? '', '/* physical')) {
                    $hits[] = $line;
                }
            }
        }
        if ($hits !== []) {
            $lines = array_values(array_unique($hits));
            $this->warnings[] = sprintf(
                '%s: %d physical left/right declaration(s) (line %s). The site is right-to-left; prefer logical properties such as margin-inline-start and inset-inline-end, or mark a deliberate one with /* physical */.',
                $file, count($hits), implode(', ', array_slice($lines, 0, 8)) . (count($lines) > 8 ? ', …' : '')
            );
        }
    }

    private function scanScript(string $file, string $source): void
    {
        foreach ($this->foreignLoads($source, true) as [$line, $url]) {
            $this->errors[] = "{$file}:{$line}: references {$url} on another origin. Nothing may be fetched from outside this site.";
        }

        $suspicious = [
            '/\bdocument\.cookie\b/'        => 'touches document.cookie; public pages set no cookies',
            '/\beval\s*\(|new\s+Function\s*\(/' => 'evaluates strings as code',
            '/["\'`]\/admin\b/'             => 'refers to /admin; theme code has no business there',
            '/\bXMLHttpRequest\b|\bWebSocket\b|\bEventSource\b/' => 'opens a network connection; use fetch() to this site\'s /api/ only',
        ];
        foreach ($suspicious as $pattern => $why) {
            if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                $this->warnings[] = "{$file}:" . $this->line($source, $m[0][1]) . ": {$why}.";
            }
        }

        if (preg_match_all('/\bfetch\s*\(\s*(["\'`])([^"\'`]*)\1/', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $call) {
                $target = $call[2][0];
                if (!str_starts_with($target, '/api/') && !str_starts_with($target, '/') && $target !== '') {
                    $this->warnings[] = "{$file}:" . $this->line($source, $call[0][1]) . ": fetch(\"{$target}\") — theme scripts should only call this site's /api/ endpoints.";
                }
            }
        }
    }

    /**
     * An SVG opened directly is a document on this origin, and static files do
     * not get the CSP header. So it may carry no script at all.
     */
    private function scanSvg(string $file, string $source): void
    {
        if (preg_match('/<script\b|\son[a-z]+\s*=|javascript:|<foreignObject\b|<!ENTITY/i', $source)) {
            $this->errors[] = "{$file}: the SVG contains script, event handlers, foreignObject or entity declarations.";
        }
        foreach ($this->foreignLoads($source) as [$line, $url]) {
            $this->errors[] = "{$file}:{$line}: references {$url} on another origin.";
        }
    }

    /**
     * URLs on another origin that the markup, style or script would load.
     * Plain <a href> links are navigation, not loads, and are allowed.
     *
     * @return list<array{int,string}> [line, url]
     */
    private function foreignLoads(string $code, bool $script = false): array
    {
        $patterns = [
            // attributes that fetch: src, srcset, poster, data, action, and <link href>
            '/\b(?:src|srcset|poster|data|action|formaction)\s*=\s*["\']?\s*((?:https?:)?\/\/[^\s"\'>]+)/i',
            '/<link\b[^>]*\bhref\s*=\s*["\']?\s*((?:https?:)?\/\/[^\s"\'>]+)/i',
            // CSS
            '/url\(\s*["\']?\s*((?:https?:)?\/\/[^\s"\')]+)/i',
            '/@import\s+(?:url\()?\s*["\']?\s*((?:https?:)?\/\/[^\s"\')]+)/i',
            // xlink:href / href on SVG <use> and <image>
            '/<(?:use|image)\b[^>]*\bhref\s*=\s*["\']?\s*((?:https?:)?\/\/[^\s"\'>]+)/i',
        ];
        if ($script) {
            // Any string literal that is a URL on another origin.
            $patterns[] = '/["\'`]((?:https?:)?\/\/[a-z0-9][^\s"\'`]*)/i';
        }

        $hits = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[1] as [$url, $offset]) {
                if (preg_match(self::NAMESPACE_URL, $url) === 1) {
                    continue;
                }
                $hits[$offset] = [$this->line($code, $offset), $url];
            }
        }
        ksort($hits);

        return array_values($hits);
    }

    // --------------------------------------------------------------- manifest

    private function checkManifest(Theme $theme): void
    {
        $manifest = $theme->manifest();

        if (($manifest['name'] ?? null) !== $theme->name) {
            $this->errors[] = sprintf('theme.json "name" is %s but the folder is "%s"; they must match.', json_encode($manifest['name'] ?? null), $theme->name);
        }
        if (isset($manifest['theme_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $manifest['theme_color']) !== 1) {
            $this->errors[] = 'theme.json "theme_color" must be #rrggbb.';
        }
        if (isset($manifest['stylesheets']) && (!is_array($manifest['stylesheets']) || !array_is_list($manifest['stylesheets']))) {
            $this->errors[] = 'theme.json "stylesheets" must be a list of paths.';
        }
        if (isset($manifest['scripts'])) {
            if (!is_array($manifest['scripts']) || (array_is_list($manifest['scripts']) && $manifest['scripts'] !== [])) {
                $this->errors[] = 'theme.json "scripts" must map page types (or "*") to lists of paths.';
            } else {
                foreach (array_keys($manifest['scripts']) as $page) {
                    if ($page !== '*' && !in_array($page, Theme::PAGE_TYPES, true)) {
                        $this->warnings[] = "theme.json \"scripts\" names page type \"{$page}\", which does not exist. Page types: " . implode(', ', Theme::PAGE_TYPES) . '.';
                    }
                }
            }
        }
        foreach ((array) ($manifest['preload_fonts'] ?? []) as $font) {
            if (!is_string($font) || preg_match('#^/assets/fonts/[A-Za-z0-9_\-.]+\.woff2$#', $font) !== 1) {
                $this->warnings[] = 'theme.json "preload_fonts": ' . json_encode($font) . ' is ignored; only /assets/fonts/*.woff2 can be preloaded.';
            }
        }

        // Every listed asset must exist and stay inside the folder.
        try {
            $theme->stylesheets();
            foreach (['*', ...Theme::PAGE_TYPES] as $page) {
                $theme->scripts($page);
            }
        } catch (TemplateError $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    // -------------------------------------------------------------- templates

    private function checkTemplates(Theme $theme): void
    {
        foreach (['layout', ...Theme::PAGE_TYPES] as $name) {
            if (!$theme->hasTemplate($name)) {
                $this->errors[] = "Missing {$name}.mustache.";
            }
        }

        if ($theme->hasTemplate('layout')) {
            $layout = $theme->template('layout');
            foreach (Theme::REQUIRED_LAYOUT_TAGS as $tag) {
                $inner = preg_quote(trim($tag, '{}'), '/');
                if (preg_match('/\{\{\{?&?\s*' . $inner . '\s*\}?\}\}/', $layout) !== 1) {
                    $this->errors[] = "layout.mustache must contain {$tag}.";
                }
            }
            if (preg_match('/<html\b[^>]*\blang\s*=\s*["\']?(\{\{\s*site\.lang\s*\}\}|fa)(?=["\'\s>])/i', $layout) !== 1
                || preg_match('/<html\b[^>]*\bdir\s*=\s*["\']?(\{\{\s*site\.dir\s*\}\}|rtl)(?=["\'\s>])/i', $layout) !== 1) {
                $this->errors[] = 'layout.mustache: <html> must carry lang="fa" dir="rtl" (or {{site.lang}} / {{site.dir}}).';
            }
        }

        // Partials referenced anywhere must exist.
        $sources = [];
        foreach (glob($theme->dir() . '/{,partials/}*.mustache', GLOB_BRACE) ?: [] as $file) {
            $sources[substr($file, strlen($theme->dir()) + 1)] = (string) file_get_contents($file);
        }
        foreach ($sources as $file => $source) {
            if (preg_match_all('/\{\{>\s*([^}\s]+)\s*\}\}/', $source, $m)) {
                foreach (array_unique($m[1]) as $partial) {
                    if (!isset($sources['partials/' . $partial . '.mustache'])) {
                        $this->errors[] = "{$file}: includes partial \"{$partial}\" but partials/{$partial}.mustache does not exist.";
                    }
                }
            }
        }
    }

    private function renderEveryPage(Theme $theme): void
    {
        foreach (Theme::PAGE_TYPES as $type) {
            try {
                $html = $theme->render($type, UiContract::fixture($type, $theme));
            } catch (TemplateError $e) {
                $this->errors[] = "{$type}: " . $e->getMessage();
                continue;
            } catch (\Throwable $e) {
                $this->errors[] = "{$type}: rendering failed: " . $e->getMessage();
                continue;
            }

            $this->rendered[$type] = strlen($html);

            if (!str_contains($html, '<meta name="robots"')) {
                $this->errors[] = "{$type}: the rendered page has no core <head> — {{page.head}} is inside a section that did not render.";
            }
            if (!str_contains($html, '/assets/core/core.js')) {
                $this->errors[] = "{$type}: the rendered page has no core scripts — {{page.foot}} did not render.";
            }
            if (!preg_match('/<main\b[^>]*\bid\s*=\s*["\']?main\b/i', $html)) {
                $this->warnings[] = "{$type}: no <main id=\"main\">.";
            }
            if (preg_match('/<[a-zA-Z][^>]*?\son[a-z]+\s*=/', $html)) {
                $this->errors[] = "{$type}: the rendered page contains an inline event handler.";
            }
        }

        if (isset($this->rendered['challenge'])) {
            $html = $theme->render('challenge', UiContract::fixture('challenge', $theme));
            foreach (['data-challenge', 'data-nonce="', 'data-difficulty="', 'data-challenge-status'] as $hook) {
                if (!str_contains($html, $hook)) {
                    $this->errors[] = "challenge: missing {$hook} — core's challenge.js needs it, or readers who trip the rate limit are stuck.";
                }
            }
        }
    }

    /** Remove comments but keep their line breaks, so reported lines stay true. */
    private function blank(string $source, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            $source = preg_replace_callback($pattern, static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $source) ?? $source;
        }

        return $source;
    }

    private function line(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, min($offset, strlen($source))) + 1;
    }
}
