<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Database;
use App\Domain\ArticleRepository;
use App\Domain\FieldRepository;
use App\Support\Slug;

/**
 * Works out what a prefix-free content URL refers to.
 *
 *   /cooking/persian/stews                 -> a field
 *   /cooking/persian/stews/ghormeh-sabzi   -> an article inside that field
 *
 * Fields are tried first, so a field and an article can never be confused:
 * an article slug that happens to match a field path simply loses, and the
 * admin refuses to create that collision in the first place.
 *
 * Worst case is two indexed lookups. Both are hash equality checks.
 */
final class ContentResolver
{
    public const TYPE_FIELD    = 'field';
    public const TYPE_ARTICLE  = 'article';
    public const TYPE_REDIRECT = 'redirect';

    /**
     * @return array{type:string, field?:array, article?:array, to?:string, status?:int}|null
     */
    public static function resolve(string $contentPath): ?array
    {
        $path = trim($contentPath, '/');
        if ($path === '') {
            return null;
        }

        $segments = Slug::splitPath($path, FieldRepository::MAX_DEPTH + 1);
        if ($segments === null) {
            return null;
        }

        $field = FieldRepository::findByPath($path);
        if ($field !== null) {
            return $field['is_published'] ? ['type' => self::TYPE_FIELD, 'field' => $field] : null;
        }

        if (count($segments) >= 2) {
            $slug = array_pop($segments);
            $parentPath = implode('/', $segments);

            $field = FieldRepository::findByPath($parentPath);
            if ($field !== null && $field['is_published']) {
                $article = ArticleRepository::findInField((int) $field['id'], $slug);
                if ($article !== null) {
                    return ['type' => self::TYPE_ARTICLE, 'field' => $field, 'article' => $article];
                }
            }
        }

        return self::findRedirect($path);
    }

    /**
     * Slug changes write a redirect so old links keep working. Looked up by
     * hash because a deep Persian path is too long to index directly.
     */
    private static function findRedirect(string $path): ?array
    {
        $row = Database::first(
            'SELECT to_path, status FROM redirects WHERE from_hash = :hash LIMIT 1',
            ['hash' => sha1($path)]
        );

        if ($row === null) {
            return null;
        }

        return [
            'type'   => self::TYPE_REDIRECT,
            'to'     => (string) $row['to_path'],
            'status' => (int) $row['status'],
        ];
    }
}
