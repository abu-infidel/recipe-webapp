<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Domain\AdminAuth;
use App\Domain\FieldRepository;
use App\Domain\Publisher;

/**
 * The field tree: create, rename, reorder, publish.
 *
 * Renaming rewrites the materialised path of the whole subtree and leaves a
 * redirect behind, so an old link never breaks.
 */
final class FieldController extends AdminController
{
    public static function index(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        return self::render($request, 'admin.fields', [
            'pageTitle' => 'Fields',
            'nav'       => 'fields',
            'tree'      => FieldRepository::tree(false),
            'flat'      => Database::all('SELECT id, path, title_fa, depth FROM fields ORDER BY path'),
        ]);
    }

    public static function create(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $parentId = (int) $request->input('parent_id', 0);

        try {
            $id = FieldRepository::create([
                'parent_id'    => $parentId > 0 ? $parentId : null,
                'title_fa'     => trim((string) $request->input('title_fa', '')),
                'slug'         => trim((string) $request->input('slug', '')) ?: null,
                'blurb_fa'     => trim((string) $request->input('blurb_fa', '')) ?: null,
                'icon'         => trim((string) $request->input('icon', '')) ?: null,
                'accent_color' => trim((string) $request->input('accent_color', '')) ?: null,
                'sort_order'   => (int) $request->input('sort_order', 0),
                'is_published' => $request->input('is_published') !== null ? 1 : 0,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::redirectWith('/admin/fields', $e->getMessage(), 'error');
        }

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'field_create', 'field', $id, $request);
        Publisher::regenerateTree();

        return self::redirectWith('/admin/fields', 'Field created.');
    }

    public static function update(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $field = FieldRepository::find($id);
        if ($field === null) {
            return Response::text('Field not found', 404);
        }

        Database::update('fields', [
            'title_fa'     => trim((string) $request->input('title_fa', $field['title_fa'])),
            'blurb_fa'     => trim((string) $request->input('blurb_fa', '')) ?: null,
            'icon'         => trim((string) $request->input('icon', '')) ?: null,
            'accent_color' => trim((string) $request->input('accent_color', '')) ?: null,
            'sort_order'   => (int) $request->input('sort_order', (int) $field['sort_order']),
            'is_published' => $request->input('is_published') !== null ? 1 : 0,
        ], 'id = :id', ['id' => $id]);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'field_update', 'field', $id, $request);

        FieldRepository::recountAll();
        Publisher::regenerateTree();
        \App\Core\PageCache::flush();

        return self::redirectWith('/admin/fields', 'Field updated.');
    }

    /**
     * Delete a field.
     *
     * Refused while it still holds articles: losing content to a mis-click is
     * far worse than having to move the articles first.
     */
    public static function delete(Request $request, array $params): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        $field = FieldRepository::find($id);
        if ($field === null) {
            return Response::text('Field not found', 404);
        }

        $articles = (int) Database::value(
            'SELECT COUNT(*) FROM articles a
             INNER JOIN fields f ON f.id = a.field_id
             WHERE f.path = :path OR f.path LIKE :prefix',
            ['path' => $field['path'], 'prefix' => $field['path'] . '/%'],
            0
        );

        if ($articles > 0) {
            return self::redirectWith(
                '/admin/fields',
                "This field still holds {$articles} article(s). Move them first.",
                'error'
            );
        }

        Database::delete('fields', 'id = :id', ['id' => $id]);

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'field_delete', 'field', $id, $request, [
            'path' => $field['path'],
        ]);

        Publisher::regenerateTree();
        \App\Core\PageCache::flush();

        return self::redirectWith('/admin/fields', 'Field deleted.');
    }
}
