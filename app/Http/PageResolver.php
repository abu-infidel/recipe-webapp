<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Request;
use App\Core\Template\Theme;
use App\Core\Url;
use App\Http\Controllers\ErrorController;
use App\Http\Controllers\SearchController;

/**
 * Which page a URL shows, and the model its template receives.
 *
 * The UI API and the admin theme preview both need "what would this URL
 * render?" without going through the front controller. This answers it the
 * way the router does, using the same view model builders, so what they
 * return is exactly what the live page uses.
 */
final class PageResolver
{
    /** Page types with no URL of their own, reachable by type. */
    public const TYPE_ONLY = ['error', 'challenge', 'offline'];

    /**
     * @return array{page_type:string, status:int, model:array|null, redirect:string|null}
     */
    public static function resolve(string $target, Theme $theme): array
    {
        $parts = parse_url($target);
        if ($parts === false) {
            return self::error(404, $theme);
        }

        $path = '/' . trim(rawurldecode((string) ($parts['path'] ?? '/')), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        switch ($path) {
            case '/':
                return self::page('home', ViewModels::home($theme));

            case '/search':
                $q = SearchController::clean(is_string($query['q'] ?? null) ? $query['q'] : '');
                $page = SearchController::page($query['page'] ?? 1);
                [$results, $hasMore] = SearchController::results($q, $page);

                return self::page('search', ViewModels::search($theme, $q, $results, $page, $hasMore));

            case '/offline':
                return self::page('offline', ViewModels::offline($theme));

            case '/about-for-ai':
                return self::page('about', ViewModels::about($theme));
        }

        // A full URL on a field subdomain maps to a content path the same way
        // a live request on that host does.
        $headers = isset($parts['host']) ? ['host' => (string) $parts['host']] : ['host' => Config::string('site.domain')];
        $contentPath = Url::contentPathFor(Request::fake('GET', $path, [], $headers));
        $match = $contentPath === null || $contentPath === '' ? null : ContentResolver::resolve($contentPath);

        return match ($match['type'] ?? null) {
            ContentResolver::TYPE_FIELD   => self::page('field', ViewModels::field($theme, $match['field'])),
            ContentResolver::TYPE_ARTICLE => self::page('article', ViewModels::article($theme, $match['field'], $match['article'])),
            ContentResolver::TYPE_REDIRECT => $match['status'] === 410
                ? self::error(410, $theme)
                : ['page_type' => 'redirect', 'status' => (int) $match['status'], 'model' => null, 'redirect' => (string) $match['to']],
            default => self::error(404, $theme),
        };
    }

    /**
     * The model for a page type that has no URL: an error page, the
     * challenge page, or the offline page.
     *
     * @return array{page_type:string, status:int, model:array|null, redirect:string|null}
     */
    public static function forType(string $type, Theme $theme): array
    {
        return match ($type) {
            'error'     => self::error(404, $theme),
            'offline'   => self::page('offline', ViewModels::offline($theme)),
            // A placeholder nonce: this model is for designing the page, and a
            // real one is bound to the address that was challenged.
            'challenge' => self::page('challenge', ViewModels::challenge(
                $theme,
                '0000000000.0000000000000000.00000000000000000000000000000000',
                Config::int('security.bot_gate.pow_difficulty', 16),
                60
            ), 429),
            default     => throw new \InvalidArgumentException("\"{$type}\" is reachable by URL; ask for it by path."),
        };
    }

    private static function error(int $code, Theme $theme): array
    {
        return self::page('error', ViewModels::error($theme, $code, ...ErrorController::MESSAGES[$code]), $code);
    }

    private static function page(string $type, array $model, int $status = 200): array
    {
        return ['page_type' => $type, 'status' => $status, 'model' => $model, 'redirect' => null];
    }
}
