<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Builds the sidebar table of contents from an article's headings, and gives
 * each heading a stable id so the TOC and the citation anchors can link to it.
 *
 * Runs at publish time, not on every request. The result is stored in
 * articles.toc_json and the ids are baked into body_html.
 */
final class Toc
{
    /**
     * Add ids to h2/h3/h4 and return both the rewritten HTML and the tree.
     *
     * @return array{html:string, toc:list<array{id:string,text:string,level:int,children:list<mixed>}>}
     */
    public static function build(string $html): array
    {
        $used = [];
        $flat = [];

        $rewritten = preg_replace_callback(
            '/<h([234])(\s[^>]*)?>(.*?)<\/h\1>/isu',
            static function (array $m) use (&$used, &$flat): string {
                $level = (int) $m[1];
                $attributes = $m[2] ?? '';
                $inner = $m[3];

                $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));

                // Respect an id the author already set; otherwise derive one.
                if (preg_match('/\bid=["\']([^"\']+)["\']/i', $attributes, $existing) === 1) {
                    $id = $existing[1];
                } else {
                    $id = self::uniqueId($text, $used);
                    $attributes .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
                }

                $used[$id] = true;
                $flat[] = ['id' => $id, 'text' => $text, 'level' => $level];

                return '<h' . $level . $attributes . '>' . $inner . '</h' . $level . '>';
            },
            $html
        ) ?? $html;

        return ['html' => $rewritten, 'toc' => self::nest($flat)];
    }

    /**
     * Turn a flat heading list into a nested one.
     *
     * Levels are treated as relative to the shallowest heading present, so an
     * article that starts at h3 nests as correctly as one that starts at h2 —
     * the pipeline does not always produce a consistent top level.
     *
     * A heading that skips a level (h2 followed by h4) is attached to the
     * nearest open ancestor rather than creating an empty rung.
     */
    private static function nest(array $flat): array
    {
        if ($flat === []) {
            return [];
        }

        $base = min(array_column($flat, 'level'));
        $roots = [];

        /** @var list<array{level:int}> $levels parallel stack of open levels */
        $levels = [];
        /** @var list<mixed> $refs parallel stack of references to open nodes */
        $refs = [];

        foreach ($flat as $heading) {
            $level = $heading['level'] - $base;
            $node = [
                'id'       => $heading['id'],
                'text'     => $heading['text'],
                'level'    => $level,
                'children' => [],
            ];

            // Close every open node at or below this level.
            while ($levels !== [] && $levels[count($levels) - 1]['level'] >= $level) {
                array_pop($levels);
                array_pop($refs);
            }

            if ($refs === []) {
                $roots[] = $node;
                $levels[] = ['level' => $level];
                $refs[] = &$roots[count($roots) - 1];
            } else {
                $parent = &$refs[count($refs) - 1];
                $parent['children'][] = $node;
                $levels[] = ['level' => $level];
                $refs[] = &$parent['children'][count($parent['children']) - 1];
                unset($parent);
            }
        }

        // Break the reference bindings before returning, or the last element
        // stays aliased and a later write to $roots would corrupt it.
        unset($refs);

        return $roots;
    }

    /**
     * A URL-safe, stable fragment id from Persian heading text.
     * Falls back to a numbered id when a heading has no usable characters.
     */
    private static function uniqueId(string $text, array $used): string
    {
        $base = Slug::make($text);
        if ($base === 'item' || $base === '') {
            $base = 'section';
        }
        $base = mb_substr($base, 0, 60, 'UTF-8');

        $id = $base;
        $n = 2;
        while (isset($used[$id])) {
            $id = $base . '-' . $n++;
        }

        return $id;
    }
}
