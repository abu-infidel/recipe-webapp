<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Domain\AdminAuth;
use App\Domain\ArticleAuthoring;
use App\Domain\ArticleComposer;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;
use App\Domain\MediaStore;

/**
 * The owner's article editor: a structured form, no HTML.
 *
 * The form is built by /assets/core/composer.js from a JSON island and posts
 * the whole document back as JSON in one field. Everything is re-validated
 * here; the script is a convenience, not a gate.
 */
final class ComposeController extends AdminController
{
    public static function create(Request $request): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $kind = in_array($request->query('kind'), ArticleAuthoring::KINDS, true) ? (string) $request->query('kind') : 'recipe';

        return self::editor($request, null, [
            'field_id' => (int) ($request->query('field') ?? 0),
            'kind'     => $kind,
            'title'    => '',
            'summary'  => '',
            'slug'     => '',
            'hero_media_id' => null,
        ], ArticleComposer::blank($kind === 'recipe'));
    }

    public static function edit(Request $request, array $params): Response
    {
        $guard = self::guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $article = ArticleRepository::find((int) ($params['id'] ?? 0));
        if ($article === null) {
            return Response::text('Article not found', 404);
        }
        if (!ArticleComposer::isComposerDocument($article['body_json'] ?? null)) {
            return self::redirectWith(
                '/admin/articles/' . (int) $article['id'],
                'This article came from the research pipeline and is edited on this screen, not in the composer.',
                'error'
            );
        }

        $doc = json_decode((string) $article['body_json'], true);

        return self::editor($request, $article, [
            'field_id' => (int) $article['field_id'],
            'kind'     => (string) $article['kind'],
            'title'    => (string) $article['title_fa'],
            'summary'  => (string) ($article['summary_fa'] ?? ''),
            'slug'     => (string) $article['slug'],
            'hero_media_id' => $article['hero_media_id'] !== null ? (int) $article['hero_media_id'] : null,
        ], $doc, json_decode((string) ($article['quality_flags'] ?? '[]'), true) ?: []);
    }

    /** POST /admin/articles/new and /admin/articles/{id}/edit */
    public static function save(Request $request, array $params = []): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $articleId = isset($params['id']) ? (int) $params['id'] : null;
        $existing = $articleId !== null ? ArticleRepository::find($articleId) : null;
        if ($articleId !== null && $existing === null) {
            return Response::text('Article not found', 404);
        }

        $meta = [
            'field_id'      => (int) $request->input('field_id', '0'),
            'kind'          => (string) $request->input('kind', ''),
            'title'         => (string) $request->input('title', ''),
            'summary'       => (string) $request->input('summary', ''),
            'slug'          => (string) $request->input('slug', ''),
            'hero_media_id' => (int) $request->input('hero_media_id', '0') ?: null,
        ];

        $posted = json_decode((string) $request->input('doc', ''), true);
        $normalized = ArticleComposer::normalize($posted);
        $doc = $normalized['doc'];
        if ($meta['kind'] !== 'recipe') {
            $doc['recipe'] = null;
        }

        if ($normalized['errors'] !== []) {
            return self::editor($request, $existing, $meta, $doc, [], $normalized['errors']);
        }

        $user = AdminAuth::user($request);
        try {
            $result = ArticleAuthoring::save($articleId, $meta, $doc, $user['id'] ?? null);
        } catch (\Throwable $e) {
            error_log('Composer save failed: ' . $e->getMessage());
            return self::editor($request, $existing, $meta, $doc, [], ['Saving failed: ' . $e->getMessage()]);
        }

        if (!$result['ok']) {
            return self::editor($request, $existing, $meta, $doc, [], $result['errors']);
        }

        AdminAuth::audit($user['id'] ?? null, $articleId === null ? 'article_create' : 'article_edit', 'article', $result['article_id'], $request, ['composer' => true]);

        $errors = count(array_filter($result['findings'], static fn($f) => ($f['severity'] ?? '') === 'error'));
        $message = $result['republished'] ? 'Saved and republished.' : 'Saved as a draft.';
        if ($errors > 0) {
            $message .= " {$errors} citation error(s) to fix — see below.";
        }

        return self::redirectWith('/admin/articles/' . $result['article_id'] . '/edit', $message, $errors > 0 ? 'error' : 'ok');
    }

    /**
     * POST /admin/media — one image, returned as JSON for the editor script.
     */
    public static function upload(Request $request): Response
    {
        $guard = self::guard($request, true);
        if ($guard !== null) {
            return $guard;
        }

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $error = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The file is larger than this server accepts (upload_max_filesize is ' . ini_get('upload_max_filesize') . ').'
                : 'No image was received.';

            return Response::json(['ok' => false, 'error' => $error], 422);
        }

        $result = MediaStore::store((string) $file['tmp_name'], [
            'kind'            => (string) $request->input('kind', 'inline'),
            'alt_fa'          => (string) $request->input('alt', ''),
            'caption_fa'      => (string) $request->input('caption', ''),
            'is_ai_generated' => $request->input('ai_generated') === '1',
        ]);

        if (!$result['ok']) {
            return Response::json($result, 422);
        }

        AdminAuth::audit(AdminAuth::user($request)['id'] ?? null, 'media_upload', 'media', $result['id'], $request);

        return Response::json([...$result, 'url' => '/media/' . $result['path']]);
    }

    private static function editor(Request $request, ?array $article, array $meta, array $doc, array $findings = [], array $errors = []): Response
    {
        $fields = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$fields): void {
            foreach ($nodes as $node) {
                $fields[] = ['id' => (int) $node['id'], 'title' => (string) $node['title_fa'], 'depth' => $depth];
                $walk($node['children'] ?? [], $depth + 1);
            }
        };
        $walk(FieldRepository::tree(false), 0);

        $mediaIds = ArticleComposer::mediaIds($doc);
        if (!empty($meta['hero_media_id'])) {
            $mediaIds[] = (int) $meta['hero_media_id'];
        }
        $media = [];
        foreach (MediaStore::byIds($mediaIds) as $id => $row) {
            $media[$id] = ['url' => '/media/' . $row['path'], 'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }

        $publicUrl = null;
        if ($article !== null && $article['status'] === 'published') {
            $field = FieldRepository::find((int) $article['field_id']);
            $publicUrl = $field !== null ? Url::article((string) $field['path'], (string) $article['slug']) : null;
        }

        return self::render($request, 'admin.compose', [
            'pageTitle' => $article === null ? 'New article' : 'Edit article',
            'nav'       => 'articles',
            'article'   => $article,
            'meta'      => $meta,
            'fields'    => $fields,
            'findings'  => $findings,
            'errors'    => $errors,
            'publicUrl' => $publicUrl,
            'editorData' => [
                'doc'      => $doc,
                'media'    => $media,
                'upload'   => '/admin/media',
                'strings'  => self::strings(),
            ],
            'scripts'   => ['/assets/core/composer.js'],
        ], $errors !== [] ? 422 : 200);
    }

    /** Editor labels. The contributor form passes Persian ones. */
    private static function strings(): array
    {
        return [
            'dir' => 'rtl',
            'intro' => 'Introduction (before the first heading)',
            'sections' => 'Sections',
            'section' => 'Section',
            'heading' => 'Heading',
            'add_section' => 'Add section',
            'add_block' => 'Add',
            'type' => 'Block type',
            'remove' => 'Remove',
            'up' => 'Move up',
            'down' => 'Move down',
            'types' => [
                'paragraph' => 'Paragraph', 'subheading' => 'Subheading', 'list' => 'Bulleted list',
                'ordered' => 'Numbered list', 'tip' => 'Tip box', 'note' => 'Note box', 'warning' => 'Warning box',
                'quote' => 'Quotation', 'image' => 'Image',
            ],
            'placeholders' => [
                'paragraph' => 'Text. A blank line starts a new paragraph. **bold**, cite with [1].',
                'list' => 'One item per line.',
                'ordered' => 'One step per line.',
                'image' => 'Caption (optional)',
            ],
            'references' => 'References',
            'reference_hint' => 'Cite a reference in the text by its number, e.g. [1]. A supporting quote lets figures be checked against it.',
            'add_reference' => 'Add reference',
            'url' => 'Address (https://…)',
            'title' => 'Title',
            'author' => 'Author',
            'date' => 'Published (YYYY-MM-DD)',
            'quote' => 'Supporting quote from the source (optional)',
            'recipe' => 'Recipe',
            'yield' => 'Serves',
            'yield_unit' => 'Unit',
            'prep' => 'Prep minutes',
            'cook' => 'Cook minutes',
            'difficulty' => 'Difficulty',
            'ingredients' => 'Ingredients',
            'add_ingredient' => 'Add ingredient',
            'quantity' => 'Amount',
            'unit' => 'Unit',
            'name' => 'Ingredient',
            'note' => 'Note',
            'steps' => 'Steps',
            'add_step' => 'Add step',
            'step' => 'Step',
            'upload' => 'Upload image',
            'uploading' => 'Uploading…',
            'replace' => 'Replace image',
            'upload_failed' => 'Upload failed',
        ];
    }
}
