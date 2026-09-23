<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Template\Theme;
use App\Http\Page;
use App\Http\ViewModels;

/**
 * A field page: sub-fields, then the articles in this branch.
 *
 * A branch with children shows the latest articles from its whole subtree, so
 * a reader landing here from search is never told a full section is empty.
 */
final class FieldController
{
    public static function show(Request $request, array $field): Response
    {
        return Page::render('field', static fn(Theme $t) => ViewModels::field($t, $field))->cacheFor(1800);
    }
}
