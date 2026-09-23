<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Core\Url;

/**
 * Serves /sw.js.
 *
 * Generated rather than static so that its precache list is built from core
 * plus the active theme's manifest. A static list drifted the moment assets
 * moved, and a service worker that fails to precache the theme's stylesheet
 * shows unstyled pages exactly when the reader is offline.
 *
 * The version is a hash of that list, so switching theme or changing an asset
 * produces a new worker, which discards the old caches.
 */
final class ServiceWorkerController
{
    public static function script(Request $request): Response
    {
        $theme = Theme::active();

        $shell = [
            '/',
            '/offline',
            Url::asset('/assets/core/persian.js'),
            Url::asset('/assets/core/core.js'),
            Url::asset('/assets/core/theme-init.js'),
            Url::asset('/assets/img/icon.svg'),
            ...$theme->preloadFonts(),
            ...$theme->stylesheets(),
            ...self::allThemeScripts($theme),
        ];
        $shell = array_values(array_unique($shell));

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/Resources/sw.template.js');
        $script = strtr($template, [
            '__VERSION__' => substr(sha1(implode('|', $shell)), 0, 12),
            '__SHELL__'   => json_encode($shell, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?: '[]',
        ]);

        return Response::text($script)
            ->withHeader('Content-Type', 'application/javascript; charset=UTF-8')
            // Browsers re-check the worker script regardless, but a short
            // lifetime keeps a theme change from waiting on an HTTP cache.
            ->withHeader('Cache-Control', 'no-cache');
    }

    /** Every script any page of the theme loads. */
    private static function allThemeScripts(Theme $theme): array
    {
        $all = [];
        foreach (Theme::PAGE_TYPES as $pageType) {
            $all = [...$all, ...$theme->scripts($pageType)];
        }

        return $all;
    }
}
