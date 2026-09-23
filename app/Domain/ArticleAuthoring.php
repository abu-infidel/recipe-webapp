<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;
use App\Support\HtmlSanitizer;
use App\Support\PersianText;
use App\Support\Slug;

/**
 * Saves an article written in the composer.
 *
 * Used by the owner's editor now and by the contributor approval flow later,
 * so both end in the same place: a normal article row whose body_html was
 * built by ArticleComposer, whose references are ordinary `sources` rows,
 * and whose citations were checked by the same validator the pipeline's
 * drafts go through. Publishing is then exactly what it always was.
 */
final class ArticleAuthoring
{
    public const KINDS = ['recipe', 'guide', 'topic'];

    /**
     * @param array{field_id?:mixed, kind?:mixed, title?:mixed, summary?:mixed, slug?:mixed, hero_media_id?:mixed} $meta
     * @param array $doc a normalised composer document
     *
     * @return array{ok:bool, article_id:?int, errors:list<string>, findings:list<array>, republished:bool}
     */
    public static function save(?int $articleId, array $meta, array $doc, ?int $editorId = null): array
    {
        $existing = $articleId !== null ? ArticleRepository::find($articleId) : null;
        if ($articleId !== null && $existing === null) {
            return self::fail(["Article {$articleId} does not exist."]);
        }

        [$row, $errors] = self::row($meta, $doc, $existing);
        if ($errors !== []) {
            return self::fail($errors);
        }

        // Citations are checked against what is on record for each address
        // plus the quote the author pasted.
        $storedText = self::storedText(array_column($doc['references'], 'url'));
        $validation = CitationValidator::validate(ArticleComposer::draft($doc), ArticleComposer::sources($doc, $storedText));

        $media = MediaStore::byIds(ArticleComposer::mediaIds($doc));
        $html = HtmlSanitizer::clean(ArticleComposer::render($doc, $media));

        $row += [
            'body_html'       => $html,
            'body_json'       => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'recipe_json'     => ($recipe = ArticleComposer::recipeJson($doc)) === null ? null : json_encode($recipe, JSON_UNESCAPED_UNICODE),
            'quality_flags'   => json_encode($validation['findings'], JSON_UNESCAPED_UNICODE),
            'reading_minutes' => PersianText::readingMinutes($html),
        ];

        $id = Database::transaction(static function () use ($existing, $row, $doc): int {
            if ($existing === null) {
                $id = Database::insert('articles', [...$row, 'status' => 'draft']);
            } else {
                $id = (int) $existing['id'];
                Database::update('articles', $row, 'id = :id', ['id' => $id]);
            }

            // The numbered bibliography, replaced wholesale: markers are the
            // references' positions in the form.
            Database::run('DELETE FROM article_sources WHERE article_id = :id', ['id' => $id]);
            foreach (self::storeReferences($doc['references']) as $index => $sourceId) {
                Database::run(
                    'INSERT INTO article_sources (article_id, source_id, marker) VALUES (:article, :source, :marker)',
                    ['article' => $id, 'source' => $sourceId, 'marker' => $index + 1]
                );
            }

            return $id;
        });

        // A live article is re-processed at once, so the public page never
        // serves a body that skipped the contents, links and search index.
        $republished = false;
        if ($existing !== null && $existing['status'] === 'published') {
            Publisher::publish($id, $editorId, 'edited');
            $republished = true;
        }

        return ['ok' => true, 'article_id' => $id, 'errors' => [], 'findings' => $validation['findings'], 'republished' => $republished];
    }

    /**
     * The article columns other than the body, validated.
     *
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private static function row(array $meta, array $doc, ?array $existing): array
    {
        $errors = [];

        $title = PersianText::display(trim(is_scalar($meta['title'] ?? null) ? (string) $meta['title'] : ''));
        if ($title === '') {
            $errors[] = 'The article needs a title.';
        } elseif (mb_strlen($title, 'UTF-8') > ArticleComposer::LIMITS['title']) {
            $errors[] = 'The title is longer than ' . ArticleComposer::LIMITS['title'] . ' characters.';
        }

        $summary = PersianText::display(trim(is_scalar($meta['summary'] ?? null) ? (string) $meta['summary'] : ''));
        if (mb_strlen($summary, 'UTF-8') > ArticleComposer::LIMITS['summary']) {
            $errors[] = 'The summary is longer than ' . ArticleComposer::LIMITS['summary'] . ' characters.';
        }

        $kind = in_array($meta['kind'] ?? null, self::KINDS, true) ? (string) $meta['kind'] : '';
        if ($kind === '') {
            $errors[] = 'Choose whether this is a recipe, a guide or a topic.';
        }
        if ($kind === 'recipe' && ($doc['recipe']['ingredients'] ?? []) === []) {
            $errors[] = 'A recipe needs at least one ingredient.';
        }

        $field = FieldRepository::find((int) ($meta['field_id'] ?? 0));
        if ($field === null) {
            $errors[] = 'Choose the section this article belongs in.';
        }

        $heroId = (int) ($meta['hero_media_id'] ?? 0);
        if ($heroId > 0 && MediaStore::byIds([$heroId]) === []) {
            $errors[] = 'The lead image no longer exists; upload it again.';
        }

        // Once published, the URL is fixed: moving it would break every link
        // and search result pointing at it.
        $published = $existing !== null && $existing['status'] === 'published';
        if ($published) {
            if ($field !== null && (int) $field['id'] !== (int) $existing['field_id']) {
                $errors[] = 'A published article cannot move to another section. Unpublish it first.';
            }
            $slug = (string) $existing['slug'];
        } else {
            $requested = is_scalar($meta['slug'] ?? null) ? trim((string) $meta['slug']) : '';
            $slug = Slug::make($requested !== '' ? $requested : $title);
            if ($slug === '' && $title !== '') {
                $errors[] = 'Could not make an address from the title; enter one.';
            }
        }

        if ($errors !== [] || $field === null) {
            return [[], $errors];
        }

        if (!$published) {
            $exists = static fn(string $candidate): bool => Database::value(
                'SELECT 1 FROM articles WHERE field_id = :field AND slug = :slug AND id <> :id',
                ['field' => $field['id'], 'slug' => $candidate, 'id' => (int) ($existing['id'] ?? 0)]
            ) !== null
                // A sub-section at that address would win over the article.
                || FieldRepository::findByPath($field['path'] . '/' . $candidate) !== null;
            $slug = Slug::unique($slug, $exists);
        }

        $row = [
            'field_id'      => (int) $field['id'],
            'slug'          => $slug,
            'kind'          => $kind,
            'title_fa'      => $title,
            'summary_fa'    => $summary !== '' ? $summary : null,
            'hero_media_id' => $heroId > 0 ? $heroId : null,
        ];

        // Set only by server code (an approved submission), never from a form.
        if (array_key_exists('author_contributor_id', $meta)) {
            $row['author_contributor_id'] = (int) $meta['author_contributor_id'] ?: null;
            $display = PersianText::display(trim((string) ($meta['author_display'] ?? '')));
            $row['author_display'] = $display !== '' ? mb_substr($display, 0, 80, 'UTF-8') : null;
        }

        return [$row, []];
    }

    /**
     * Each reference as a `sources` row, reusing one already on record for
     * the same address. A hand-entered reference never overwrites text the
     * pipeline fetched; it only fills in a missing title or author.
     *
     * @return list<int> source ids, in reference order
     */
    private static function storeReferences(array $references): array
    {
        $ids = [];

        foreach ($references as $reference) {
            $url = (string) $reference['url'];
            $hash = sha1($url);
            $existing = Database::first('SELECT id, title, author, published_date FROM sources WHERE url_hash = :hash', ['hash' => $hash]);

            if ($existing !== null) {
                $fill = array_filter([
                    'title'          => empty($existing['title']) && $reference['title'] !== '' ? $reference['title'] : null,
                    'author'         => empty($existing['author']) && $reference['author'] !== '' ? $reference['author'] : null,
                    'published_date' => empty($existing['published_date']) && $reference['published_date'] !== '' ? $reference['published_date'] : null,
                ], static fn($v) => $v !== null);
                if ($fill !== []) {
                    Database::update('sources', $fill, 'id = :id', ['id' => (int) $existing['id']]);
                }
                $ids[] = (int) $existing['id'];
                continue;
            }

            $ids[] = Database::insert('sources', [
                'url'            => $url,
                'url_hash'       => $hash,
                'domain'         => mb_substr((string) (parse_url($url, PHP_URL_HOST) ?: ''), 0, 255, 'UTF-8'),
                'title'          => $reference['title'] !== '' ? $reference['title'] : null,
                'author'         => $reference['author'] !== '' ? $reference['author'] : null,
                'published_date' => $reference['published_date'] !== '' ? $reference['published_date'] : null,
                'trust_tier'     => 3,
            ]);
        }

        return $ids;
    }

    /** @return array<string,string> url => extracted text already on record */
    private static function storedText(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        [$placeholders, $params] = Database::inClause(array_map('sha1', $urls), 'h');

        return Database::pairs(
            "SELECT url, COALESCE(extracted_text, '') FROM sources WHERE url_hash IN ({$placeholders})",
            $params
        );
    }

    private static function fail(array $errors): array
    {
        return ['ok' => false, 'article_id' => null, 'errors' => $errors, 'findings' => [], 'republished' => false];
    }
}
