<?php
declare(strict_types=1);

namespace App\Http\Seo;

use App\Core\Config;
use App\Core\Template\SafeHtml;
use App\Core\Template\Theme;
use App\Core\Url;

/**
 * Builds the <head> and the closing scripts for every public page.
 *
 * Owned by core, not by themes. A theme places {{page.head}} and
 * {{page.foot}} and nothing else, so a redesign — by a person or a model —
 * cannot break titles, canonical URLs, structured data, social previews, the
 * CSP-safe script loading, or anonymous counting.
 */
final class Head
{
    /**
     * @param array{
     *   type:string, title:string, description?:string, canonical?:?string,
     *   noindex?:bool, og_type?:string, image?:?array, published?:?string,
     *   modified?:?string, section?:?string, json_ld?:list<array>, article_id?:?int
     * } $meta
     */
    public static function build(array $meta, Theme $theme): SafeHtml
    {
        $siteName = Config::string('site.name_fa');
        $title = self::title($meta, $siteName);
        $description = self::clip((string) ($meta['description'] ?? ''), 160);
        $canonical = $meta['canonical'] ?? null;
        $image = $meta['image'] ?? self::defaultImage();

        $h = [];
        $h[] = '<meta charset="UTF-8">';
        $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        $h[] = '<title>' . self::e($title) . '</title>';

        if ($description !== '') {
            $h[] = '<meta name="description" content="' . self::e($description) . '">';
        }

        // Large image previews and full snippets are explicitly allowed:
        // without max-image-preview:large, Google shows only a thumbnail.
        $h[] = !empty($meta['noindex'])
            ? '<meta name="robots" content="noindex, follow">'
            : '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">';

        if ($canonical !== null) {
            $h[] = '<link rel="canonical" href="' . self::e($canonical) . '">';
        }

        // Social previews: Telegram, WhatsApp and Twitter all read these, and
        // a link shared in a Persian channel is a large share of discovery.
        $h[] = '<meta property="og:site_name" content="' . self::e($siteName) . '">';
        $h[] = '<meta property="og:locale" content="fa_IR">';
        $h[] = '<meta property="og:type" content="' . self::e($meta['og_type'] ?? 'website') . '">';
        $h[] = '<meta property="og:title" content="' . self::e((string) $meta['title']) . '">';
        if ($description !== '') {
            $h[] = '<meta property="og:description" content="' . self::e($description) . '">';
        }
        if ($canonical !== null) {
            $h[] = '<meta property="og:url" content="' . self::e($canonical) . '">';
        }
        if ($image !== null) {
            $h[] = '<meta property="og:image" content="' . self::e($image['url']) . '">';
            $h[] = '<meta property="og:image:width" content="' . (int) $image['width'] . '">';
            $h[] = '<meta property="og:image:height" content="' . (int) $image['height'] . '">';
            $h[] = '<meta property="og:image:alt" content="' . self::e((string) ($image['alt'] ?? $meta['title'])) . '">';
        }
        if (($meta['og_type'] ?? '') === 'article') {
            if (!empty($meta['published'])) {
                $h[] = '<meta property="article:published_time" content="' . self::e((string) $meta['published']) . '">';
            }
            if (!empty($meta['modified'])) {
                $h[] = '<meta property="article:modified_time" content="' . self::e((string) $meta['modified']) . '">';
            }
            if (!empty($meta['section'])) {
                $h[] = '<meta property="article:section" content="' . self::e((string) $meta['section']) . '">';
            }
        }
        $h[] = '<meta name="twitter:card" content="' . ($image !== null ? 'summary_large_image' : 'summary') . '">';

        $h[] = '<link rel="alternate" type="application/atom+xml" title="' . self::e($siteName) . '" href="' . self::e(Url::base() . '/feed.xml') . '">';
        $h[] = '<link rel="icon" href="' . self::e(Url::asset('/assets/img/icon.svg')) . '" type="image/svg+xml">';
        $h[] = '<link rel="apple-touch-icon" href="' . self::e(Url::asset('/assets/img/logo-512.png')) . '">';
        $h[] = '<link rel="manifest" href="/manifest.webmanifest">';
        $h[] = '<meta name="theme-color" content="' . self::e(self::color($theme->manifest()['theme_color'] ?? null)) . '">';

        // Search-console ownership tags, set in config.local.php.
        foreach ([
            'google-site-verification' => 'seo.verification.google',
            'msvalidate.01'            => 'seo.verification.bing',
            'yandex-verification'      => 'seo.verification.yandex',
        ] as $name => $key) {
            $value = Config::string($key);
            if ($value !== '' && preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $value) === 1) {
                $h[] = '<meta name="' . $name . '" content="' . self::e($value) . '">';
            }
        }

        if (!empty($meta['article_id'])) {
            // Read by core.js for the anonymous view beacon.
            $h[] = '<meta name="article-id" content="' . (int) $meta['article_id'] . '">';
        }

        foreach ($theme->preloadFonts() as $font) {
            $h[] = '<link rel="preload" href="' . self::e($font) . '" as="font" type="font/woff2" crossorigin>';
        }

        // Before the stylesheet, so a reader who chose dark mode never sees
        // a white flash. External, because the CSP forbids inline script.
        $h[] = '<script src="' . self::e(Url::asset('/assets/core/theme-init.js')) . '"></script>';

        foreach ($theme->stylesheets() as $sheet) {
            $h[] = '<link rel="stylesheet" href="' . self::e($sheet) . '">';
        }

        // Core styles for core-owned pages (the account area), after the
        // theme's so they can use its custom properties.
        foreach ((array) ($meta['core_styles'] ?? []) as $sheet) {
            if (is_string($sheet) && preg_match('#^/assets/core/[a-z0-9\-]+\.css$#', $sheet) === 1) {
                $h[] = '<link rel="stylesheet" href="' . self::e(Url::asset($sheet)) . '">';
            }
        }

        foreach ((array) ($meta['json_ld'] ?? []) as $graph) {
            $h[] = '<script type="application/ld+json">' . self::json($graph) . '</script>';
        }

        return new SafeHtml(implode("\n", $h));
    }

    /** Core scripts first, then the theme's, all deferred and same-origin. */
    public static function foot(string $pageType, Theme $theme, array $extraCore = []): SafeHtml
    {
        $scripts = [
            Url::asset('/assets/core/persian.js'),
            Url::asset('/assets/core/core.js'),
            ...array_map(static fn(string $p) => Url::asset($p), $extraCore),
            ...$theme->scripts($pageType),
        ];

        $html = array_map(static fn(string $src) => '<script src="' . self::e($src) . '" defer></script>', $scripts);

        // The scraper honeypot lives here rather than in a theme: if a
        // redesign ever dropped the styles hiding it, a reader could click it
        // and be blocked for hours. Inline, so no stylesheet can un-hide it.
        $html[] = '<a href="' . self::e(Config::string('security.bot_gate.honeypot_path', '/archive/all-entries'))
            . '" aria-hidden="true" tabindex="-1" rel="nofollow"'
            // Clipped to nothing rather than pushed off-screen: off-screen
            // positioning depends on the page's direction and overflow rules,
            // clip-path does not, and a clipped element cannot be clicked.
            . ' style="position:absolute;width:1px;height:1px;margin:-1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap">.</a>';

        return new SafeHtml(implode("\n", $html));
    }

    private static function title(array $meta, string $siteName): string
    {
        if (($meta['type'] ?? '') === 'home') {
            $tagline = Config::string('site.tagline_fa');

            return $tagline !== '' ? $siteName . ' — ' . $tagline : $siteName;
        }

        $title = trim((string) ($meta['title'] ?? ''));

        return $title === '' ? $siteName : $title . ' — ' . $siteName;
    }

    /** The site-wide share image, used when a page has none of its own. */
    public static function defaultImage(): ?array
    {
        $path = '/assets/img/og-default.png';
        if (!is_file(\App\Core\Paths::public() . $path)) {
            return null;
        }

        return [
            'url'    => Url::base() . $path,
            'width'  => 1200,
            'height' => 630,
            'alt'    => Config::string('site.name_fa'),
        ];
    }

    /**
     * JSON for a <script> island. The HEX flags turn <, >, & and quotes into
     * \u escapes, so no value can close the script element early.
     */
    public static function json(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: 'null';
    }

    private static function color(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : '#b4541f';
    }

    private static function clip(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');

        return ($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space, 'UTF-8') : $cut) . '…';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
