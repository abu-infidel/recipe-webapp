<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;

/**
 * A field page: the articles in this branch, plus any sub-fields.
 *
 * A leaf field shows its articles. A branch with children shows those
 * children as tiles and, beneath them, the most recent articles from the
 * whole subtree — so a reader who lands here from a search is never told the
 * section is empty when it plainly is not.
 */
final class FieldController
{
    public static function show(Request $request, array $field): Response
    {
        $fieldId = (int) $field['id'];
        $children = FieldRepository::children($fieldId);

        $articles = $children === []
            ? ArticleRepository::inField($fieldId, 60)
            : ArticleRepository::inSubtree((string) $field['path'], 24);

        $html = View::page('public.field', 'public.layout', [
            'field'       => $field,
            'children'    => $children,
            'articles'    => $articles,
            'ancestors'   => FieldRepository::ancestors((string) $field['path']),
            'pageTitle'   => $field['title_fa'],
            'description' => $field['blurb_fa'] ?? '',
            'bodyClass'   => 'page-field',
            'accent'      => $field['accent_color'] ?? null,
            'canonical'   => \App\Core\Url::field((string) $field['path']),
        ]);

        return Response::html($html)->cacheFor(1800);
    }
}
