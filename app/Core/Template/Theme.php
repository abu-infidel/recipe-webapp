<?php
declare(strict_types=1);

namespace App\Core\Template;

use App\Core\Config;
use App\Core\Paths;

/**
 * A theme: everything a reader sees, in one folder.
 *
 *   public/themes/<name>/
 *     theme.json            name, assets, which scripts load on which page
 *     layout.mustache       the page shell
 *     <page>.mustache       one per page type (home, field, article, …)
 *     partials/*.mustache
 *     *.css, *.js           the theme's own assets
 *
 * A theme is a pure function of the data it is given. It cannot run PHP,
 * query the database or emit unescaped HTML, so it can be rewritten — by a
 * person or a language model — without touching, or even reading, the
 * backend. docs/UI-CONTRACT.md describes the data; tools/theme-check.php
 * validates a theme against it.
 */
final class Theme
{
    public const PAGE_TYPES = ['home', 'field', 'article', 'search', 'error', 'offline', 'about', 'challenge'];

    /** Every layout must place these, or SEO, the CSP-safe scripts and counting break. */
    public const REQUIRED_LAYOUT_TAGS = ['{{page.head}}', '{{page.foot}}', '{{content}}'];

    private static ?self $active = null;

    private function __construct(
        public readonly string $name,
        private readonly string $dir,
        private readonly array $manifest,
    ) {}

    public static function active(): self
    {
        if (self::$active === null) {
            $name = Config::string('ui.theme', 'default');

            try {
                self::$active = self::load($name);
            } catch (TemplateError $e) {
                // A broken or missing configured theme falls back rather than
                // taking the site down. The error is logged for whoever broke it.
                error_log("Theme \"{$name}\" unusable, falling back to default: " . $e->getMessage());
                self::$active = self::load('default');
            }
        }

        return self::$active;
    }

    public static function load(string $name): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,40}$/', $name) !== 1) {
            throw new TemplateError("Invalid theme name \"{$name}\".");
        }

        $dir = Paths::themes() . '/' . $name;
        $manifestFile = $dir . '/theme.json';

        if (!is_file($manifestFile)) {
            throw new TemplateError("Theme \"{$name}\" has no theme.json at {$manifestFile}.");
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            throw new TemplateError("Theme \"{$name}\": theme.json is not valid JSON.");
        }

        if ((int) ($manifest['api'] ?? 0) !== 1) {
            throw new TemplateError("Theme \"{$name}\" targets UI API " . var_export($manifest['api'] ?? null, true) . '; this site provides API 1.');
        }

        return new self($name, $dir, $manifest);
    }

    /** Point active() at a specific theme. Used by the theme checker and tests. */
    public static function use(?self $theme): void
    {
        self::$active = $theme;
    }

    /**
     * Render one page: the page template, then the layout around it.
     *
     * @param array<string,mixed> $model the view model, exactly as /api/v1/page returns it
     */
    public function render(string $pageType, array $model): string
    {
        if (!in_array($pageType, self::PAGE_TYPES, true)) {
            throw new TemplateError("Unknown page type \"{$pageType}\".");
        }

        $engine = $this->engine();

        $content = $engine->render($this->template($pageType), $model, $pageType . '.mustache');

        return $engine->render($this->template('layout'), [...$model, 'content' => new SafeHtml($content)], 'layout.mustache');
    }

    public function hasTemplate(string $name): bool
    {
        return is_file($this->templatePath($name));
    }

    public function template(string $name): string
    {
        $path = $this->templatePath($name);
        if (!is_file($path)) {
            throw new TemplateError("Theme \"{$this->name}\" has no {$name}.mustache.");
        }

        return (string) file_get_contents($path);
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Public URL of the theme folder. */
    public function url(): string
    {
        return '/themes/' . $this->name;
    }

    /** Fingerprinted stylesheet URLs, in manifest order. */
    public function stylesheets(): array
    {
        return array_map($this->assetUrl(...), array_values((array) ($this->manifest['stylesheets'] ?? [])));
    }

    /** Scripts for every page, then scripts for this page type. */
    public function scripts(string $pageType): array
    {
        $scripts = (array) ($this->manifest['scripts'] ?? []);
        $list = [...(array) ($scripts['*'] ?? []), ...(array) ($scripts[$pageType] ?? [])];

        return array_map($this->assetUrl(...), array_values(array_unique($list)));
    }

    /** Fonts worth preloading. Must be same-origin paths. */
    public function preloadFonts(): array
    {
        $fonts = [];
        foreach ((array) ($this->manifest['preload_fonts'] ?? []) as $font) {
            if (is_string($font) && preg_match('#^/assets/fonts/[A-Za-z0-9_\-.]+\.woff2$#', $font) === 1) {
                $fonts[] = \App\Core\Url::asset($font);
            }
        }

        return $fonts;
    }

    /**
     * A theme asset's URL with a fingerprint from its modification time, so
     * a far-future cache is safe and a changed file is fetched at once.
     * Paths that could escape the theme folder or point off-site are refused.
     */
    public function assetUrl(string $relative): string
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_\-./]*$#', $relative) !== 1 || str_contains($relative, '..')) {
            throw new TemplateError("Theme \"{$this->name}\": asset path \"{$relative}\" is not allowed.");
        }

        $file = $this->dir . '/' . $relative;
        if (!is_file($file)) {
            throw new TemplateError("Theme \"{$this->name}\": asset \"{$relative}\" listed in theme.json does not exist.");
        }

        return $this->url() . '/' . $relative . '?v=' . substr((string) filemtime($file), -6);
    }

    private function engine(): Mustache
    {
        $partials = $this->dir . '/partials';

        return new Mustache(static function (string $name) use ($partials): ?string {
            $file = $partials . '/' . $name . '.mustache';

            return is_file($file) ? (string) file_get_contents($file) : null;
        });
    }

    private function templatePath(string $name): string
    {
        return $this->dir . '/' . $name . '.mustache';
    }
}
