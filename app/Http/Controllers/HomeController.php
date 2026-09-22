<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\FieldRepository;

/**
 * The homepage, which serves no content of its own — it is the menu.
 *
 * The whole published tree ships with the page as a JSON island, so every
 * drill-down is instant and needs no network request. The tree is small (a
 * few KB even with hundreds of fields) and this is what makes the animated
 * menu feel immediate on a slow connection.
 */
final class HomeController
{
    public static function index(Request $request): Response
    {
        $tree = FieldRepository::tree();

        $html = View::page('public.home', 'public.layout', [
            'tree'        => $tree,
            'pageTitle'   => Config::string('site.name_fa'),
            'description' => Config::string('site.tagline_fa'),
            'bodyClass'   => 'page-home',
            'isHome'      => true,
            'scripts'     => ['/assets/js/menu.js'],
        ]);

        return Response::html($html)->cacheFor(3600);
    }

    /**
     * The tree as JSON.
     *
     * Also written to public/cache/tree.json on publish, where LiteSpeed
     * serves it without starting PHP.
     */
    public static function tree(Request $request): Response
    {
        return Response::json([
            'generated' => gmdate('c'),
            'fields'    => FieldRepository::tree(),
        ])->cacheFor(3600);
    }
}
