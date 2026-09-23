<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Domain\FieldRepository;
use App\Http\PageResolver;
use App\Http\UiContract;
use App\Http\ViewModels;

/**
 * UI API v1: everything a theme author needs, without the repository.
 *
 * Read-only, and it returns only what the public pages already show — the
 * page endpoint is the same data as the HTML page, as JSON. It draws on the
 * same rate budget as page views (see BotGate), so it is not a cheaper way to
 * bulk-copy the site than crawling it.
 */
final class UiApiController
{
    public static function schema(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        return Response::json(UiContract::schema())->cacheFor(3600);
    }

    /** /api/v1/page?path=/ashpazi  or  ?type=error|challenge|offline */
    public static function page(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        $theme = Theme::active();
        $type = (string) $request->query('type', '');
        $path = (string) $request->query('path', '');

        try {
            if ($type !== '') {
                if (!in_array($type, PageResolver::TYPE_ONLY, true)) {
                    return self::badRequest('type must be one of: ' . implode(', ', PageResolver::TYPE_ONLY) . '. Other page types have URLs; use ?path=.');
                }
                $resolved = PageResolver::forType($type, $theme);
            } elseif (str_starts_with($path, '/') || preg_match('#^https?://#', $path) === 1) {
                $resolved = PageResolver::resolve(mb_substr($path, 0, 500, 'UTF-8'), $theme);
            } else {
                return self::badRequest('Pass ?path=/some/url (root-relative, may include ?q=… for /search) or ?type=error|challenge|offline.');
            }
        } catch (\InvalidArgumentException $e) {
            return self::badRequest($e->getMessage());
        }

        return Response::json([
            'api'       => UiContract::API_VERSION,
            'page_type' => $resolved['page_type'],
            'template'  => $resolved['model'] === null ? null : $resolved['page_type'] . '.mustache',
            'status'    => $resolved['status'],
            'redirect'  => $resolved['redirect'],
            'theme'     => $theme->name,
            'model'     => $resolved['model'],
        ])->cacheFor(300);
    }

    /** /api/v1/fixture?type=article — deterministic sample data, every branch filled. */
    public static function fixture(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        $type = (string) $request->query('type', '');
        if (!in_array($type, Theme::PAGE_TYPES, true)) {
            return self::badRequest('type must be one of: ' . implode(', ', Theme::PAGE_TYPES) . '.');
        }

        $theme = Theme::active();

        return Response::json([
            'api'       => UiContract::API_VERSION,
            'page_type' => $type,
            'template'  => $type . '.mustache',
            'theme'     => $theme->name,
            'model'     => UiContract::fixture($type, $theme),
        ])->cacheFor(3600);
    }

    public static function tree(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        return Response::json([
            'api'    => UiContract::API_VERSION,
            'fields' => array_map(ViewModels::node(...), FieldRepository::tree()),
        ])->cacheFor(600);
    }

    public static function search(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        $query = SearchController::clean((string) $request->query('q', ''));
        $page = SearchController::page($request->query('page'));
        [$results, $hasMore] = SearchController::results($query, $page);
        $model = ViewModels::search(Theme::active(), $query, $results, $page, $hasMore);

        return Response::json([
            'api'         => UiContract::API_VERSION,
            'query'       => $model['query'],
            'results'     => $model['results'],
            'has_results' => $model['has_results'],
            'pagination'  => $model['pagination'],
        ])->cacheFor(60);
    }

    /** The active theme: manifest, template sources, asset URLs. */
    public static function theme(Request $request): Response
    {
        if (($off = self::disabled()) !== null) {
            return $off;
        }

        $theme = Theme::active();
        $templates = [];
        foreach (glob($theme->dir() . '/{,partials/}*.mustache', GLOB_BRACE) ?: [] as $file) {
            $templates[substr($file, strlen($theme->dir()) + 1)] = (string) file_get_contents($file);
        }
        ksort($templates);

        $assets = [];
        foreach (glob($theme->dir() . '/*.{css,js}', GLOB_BRACE) ?: [] as $file) {
            $assets[] = $theme->url() . '/' . basename($file);
        }

        return Response::json([
            'api'       => UiContract::API_VERSION,
            'name'      => $theme->name,
            'manifest'  => $theme->manifest(),
            'base_url'  => $theme->url() . '/',
            'assets'    => $assets,
            'templates' => $templates,
        ])->cacheFor(600);
    }

    private static function disabled(): ?Response
    {
        return Config::bool('ui.api.enabled', true)
            ? null
            : Response::json(['error' => 'The UI API is switched off on this site.'], 404);
    }

    private static function badRequest(string $message): Response
    {
        return Response::json(['error' => $message, 'schema' => '/api/v1/schema'], 400);
    }
}
