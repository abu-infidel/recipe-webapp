<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\FieldRepository;
use App\Http\Page;
use App\Http\ViewModels;

/**
 * The homepage, which serves no content of its own — it is the menu.
 *
 * The whole published tree ships with the page as a JSON island, so every
 * drill-down is instant and needs no request.
 */
final class HomeController
{
    public static function index(Request $request): Response
    {
        return Page::render('home', ViewModels::home(...))->cacheFor(3600);
    }

    /** The tree as JSON. */
    public static function tree(Request $request): Response
    {
        return Response::json([
            'generated' => gmdate('c'),
            'fields'    => FieldRepository::tree(),
        ])->cacheFor(3600);
    }
}
