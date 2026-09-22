<?php
declare(strict_types=1);

namespace App\Support;

/**
 * URL slug generation.
 *
 * Persian slugs are kept in Persian. Percent-encoded UTF-8 is ugly when a URL
 * is pasted raw, but every modern browser displays it decoded, Persian
 * Wikipedia has done it for two decades, and a transliterated slug is far
 * worse: readers cannot recognise it and search engines cannot match it to
 * the query.
 */
final class Slug
{
    private const MAX_LENGTH = 120;

    /**
     * Build a slug from a title.
     *
     * Persian letters and digits survive; everything else becomes a hyphen.
     * ZWNJ becomes a hyphen too, because a half-space inside a URL is
     * invisible and would produce two slugs that look identical.
     */
    public static function make(string $title): string
    {
        $text = str_replace(PersianText::ZWNJ, '-', trim($title));
        $text = PersianText::display($text);
        $text = mb_strtolower($text, 'UTF-8');

        // Fold Arabic-keyboard variants so the same title never yields two slugs.
        $text = PersianText::toAsciiDigits($text);

        // Keep letters, marks and digits; everything else separates.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $text) ?? $text;
        $text = trim($text, '-');
        $text = preg_replace('/-{2,}/', '-', $text) ?? $text;

        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8');
            // Do not end mid-word.
            $lastHyphen = mb_strrpos($text, '-', 0, 'UTF-8');
            if ($lastHyphen !== false && $lastHyphen > self::MAX_LENGTH / 2) {
                $text = mb_substr($text, 0, $lastHyphen, 'UTF-8');
            }
        }

        return $text !== '' ? $text : 'item';
    }

    /**
     * Append -2, -3 … until the slug is unique.
     *
     * @param callable(string):bool $exists
     */
    public static function unique(string $slug, callable $exists): string
    {
        if (!$exists($slug)) {
            return $slug;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $slug . '-' . $n;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        return $slug . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Validate a slug arriving from a URL before it reaches a query.
     *
     * Rejects path traversal, empty segments and anything with a character
     * class we never generate.
     */
    public static function isValid(string $slug): bool
    {
        if ($slug === '' || mb_strlen($slug, 'UTF-8') > self::MAX_LENGTH + 12) {
            return false;
        }
        if (str_contains($slug, '..') || str_contains($slug, '/') || str_contains($slug, '\\')) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{M}\p{N}]+(?:-[\p{L}\p{M}\p{N}]+)*$/u', $slug);
    }

    /**
     * Split a materialised field path ("cooking/persian/stews") into segments,
     * validating each. Returns null if any segment is unacceptable.
     *
     * @return list<string>|null
     */
    public static function splitPath(string $path, int $maxDepth = 8): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));

        if ($segments === [] || count($segments) > $maxDepth) {
            return null;
        }
        foreach ($segments as $segment) {
            if (!self::isValid($segment)) {
                return null;
            }
        }

        return $segments;
    }
}
