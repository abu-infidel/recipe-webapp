<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Http\Page;
use App\Http\ViewModels;

/**
 * An article page.
 *
 * Everything shown was computed at publish time — the contents, the internal
 * links, the reading time — so rendering is a handful of indexed reads.
 * Views are counted by an anonymous beacon from the browser, never here: this
 * runs only on a cache miss.
 */
final class ArticleController
{
    public static function show(Request $request, array $field, array $article): Response
    {
        return Page::render('article', static fn(Theme $t) => ViewModels::article($t, $field, $article))->cacheFor(1800);
    }
}
