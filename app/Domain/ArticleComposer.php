<?php
declare(strict_types=1);

namespace App\Domain;

use App\Support\PersianText;
use App\Support\UrlGuard;

/**
 * Structured articles: written in a form, never in HTML.
 *
 * The site owner's editor and the contributor form both produce a document in
 * this shape, and only this class turns it into HTML. Authors cannot write
 * markup at all, so there is nothing to sanitise away and nothing to smuggle
 * in; the HTML that comes out is built from escaped text and a handful of
 * known tags, and is still sanitised again at publish.
 *
 * The document ("composer/1"), stored in articles.body_json:
 *
 *   intro:      list<block>                 before the first heading
 *   sections:   list<{heading, blocks: list<block>}>
 *   references: list<{url, title, author, published_date, quote}>
 *   recipe:     null | {yield_number, yield_unit, prep_minutes, cook_minutes,
 *                       difficulty, ingredients: list<{quantity, unit, name,
 *                       note}>, steps: list<{text}>}
 *
 *   block:      {type, text, media_id}
 *     paragraph   text; a blank line starts a new paragraph
 *     subheading  a smaller heading inside a section
 *     list        one item per line          ordered — the same, numbered
 *     tip, note, warning   a callout box
 *     quote       a quotation
 *     image       media_id, with text as the caption
 *
 * Inside text an author may write **bold**, and cite a reference by its
 * number: [2], [۲], or several at once as [1، 3]. Citations are checked by
 * CitationValidator against the article's own references, exactly as the
 * pipeline's drafts are.
 */
final class ArticleComposer
{
    public const FORMAT = 'composer/1';

    public const BLOCK_TYPES = ['paragraph', 'subheading', 'list', 'ordered', 'tip', 'note', 'warning', 'quote', 'image'];

    public const LIMITS = [
        'sections'    => 40,
        'blocks'      => 60,     // per section, and for the intro
        'text'        => 5000,   // characters per block
        'heading'     => 150,
        'references'  => 40,
        'ingredients' => 60,
        'steps'       => 60,
        'title'       => 200,
        'summary'     => 600,
    ];

    /** A citation marker: [3], [۳], [1، 2], [1,2]. */
    private const CITE = '/\[\s*([0-9۰-۹٠-٩]{1,3}(?:\s*[,،]\s*[0-9۰-۹٠-٩]{1,3})*)\s*\]/u';

    // ------------------------------------------------------------ normalising

    /**
     * Turn untrusted input — a form post decoded from JSON, or a contributor's
     * submission — into a clean document. Unknown keys are dropped, types are
     * coerced, limits enforced. Problems that make the document unusable are
     * returned as errors; everything else is quietly tidied.
     *
     * @return array{doc: array, errors: list<string>}
     */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $errors = [];

        $intro = self::blocks($input['intro'] ?? [], 'Introduction', $errors);

        $sections = [];
        foreach (array_slice(self::listOf($input['sections'] ?? []), 0, self::LIMITS['sections']) as $i => $section) {
            $heading = self::text($section['heading'] ?? '', self::LIMITS['heading'], true);
            $blocks = self::blocks($section['blocks'] ?? [], 'Section ' . ($i + 1), $errors);
            if ($heading === '' && $blocks === []) {
                continue;   // an empty row left in the form
            }
            if ($heading === '') {
                $errors[] = 'Section ' . ($i + 1) . ' has content but no heading.';
            }
            $sections[] = ['heading' => $heading, 'blocks' => $blocks];
        }
        if (count(self::listOf($input['sections'] ?? [])) > self::LIMITS['sections']) {
            $errors[] = 'At most ' . self::LIMITS['sections'] . ' sections.';
        }

        $references = [];
        $seen = [];
        foreach (self::listOf($input['references'] ?? []) as $reference) {
            $url = trim((string) ($reference['url'] ?? ''));
            $title = self::text($reference['title'] ?? '', 500, true);
            if ($url === '' && $title === '') {
                continue;
            }
            $number = count($references) + 1;
            if (!UrlGuard::isHttpUrl($url)) {
                $errors[] = "Reference {$number}: the address must start with http:// or https://.";
            } elseif (isset($seen[$url])) {
                // One source, one number, or the numbering would have a gap.
                $errors[] = "Reference {$number} is the same address as reference {$seen[$url]}; cite [{$seen[$url]}] instead.";
                continue;
            }
            $seen[$url] = $number;
            $date = trim((string) ($reference['published_date'] ?? ''));
            $references[] = [
                'url'            => mb_substr($url, 0, 2048, 'UTF-8'),
                'title'          => $title,
                'author'         => self::text($reference['author'] ?? '', 255, true),
                'published_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : '',
                'quote'          => self::text($reference['quote'] ?? '', 2000),
            ];
        }
        if (count($references) > self::LIMITS['references']) {
            $errors[] = 'At most ' . self::LIMITS['references'] . ' references.';
            $references = array_slice($references, 0, self::LIMITS['references']);
        }

        $recipe = isset($input['recipe']) && is_array($input['recipe']) ? self::recipe($input['recipe'], $errors) : null;

        if ($intro === [] && $sections === []) {
            $errors[] = 'The article has no text.';
        }

        return [
            'doc' => [
                'format'     => self::FORMAT,
                'intro'      => $intro,
                'sections'   => $sections,
                'references' => $references,
                'recipe'     => $recipe,
            ],
            'errors' => $errors,
        ];
    }

    /** A new, empty document for the editor. */
    public static function blank(bool $recipe = false): array
    {
        return [
            'format'     => self::FORMAT,
            'intro'      => [['type' => 'paragraph', 'text' => '', 'media_id' => null]],
            'sections'   => [],
            'references' => [],
            'recipe'     => $recipe ? self::emptyRecipe() : null,
        ];
    }

    public static function emptyRecipe(): array
    {
        return [
            'yield_number' => null, 'yield_unit' => 'نفر', 'prep_minutes' => null, 'cook_minutes' => null,
            'difficulty' => '', 'ingredients' => [], 'steps' => [],
        ];
    }

    public static function isComposerDocument(mixed $json): bool
    {
        $doc = is_string($json) ? json_decode($json, true) : $json;

        return is_array($doc) && ($doc['format'] ?? null) === self::FORMAT;
    }

    private static function blocks(mixed $blocks, string $where, array &$errors): array
    {
        $out = [];
        $list = self::listOf($blocks);

        foreach ($list as $block) {
            $type = in_array($block['type'] ?? null, self::BLOCK_TYPES, true) ? $block['type'] : 'paragraph';
            $mediaId = isset($block['media_id']) && (int) $block['media_id'] > 0 ? (int) $block['media_id'] : null;
            $singleLine = in_array($type, ['subheading', 'image'], true);
            $text = self::text($block['text'] ?? '', $type === 'subheading' ? self::LIMITS['heading'] : self::LIMITS['text'], $singleLine);

            if ($type === 'image' ? $mediaId === null : $text === '') {
                continue;
            }
            if (mb_strlen((string) ($block['text'] ?? ''), 'UTF-8') > self::LIMITS['text']) {
                $errors[] = "{$where}: a block is longer than " . self::LIMITS['text'] . ' characters; split it.';
            }

            $out[] = ['type' => $type, 'text' => $text, 'media_id' => $type === 'image' ? $mediaId : null];
        }

        if (count($out) > self::LIMITS['blocks']) {
            $errors[] = "{$where}: at most " . self::LIMITS['blocks'] . ' blocks.';
            $out = array_slice($out, 0, self::LIMITS['blocks']);
        }

        return $out;
    }

    private static function recipe(array $input, array &$errors): array
    {
        $minutes = static function (mixed $value): ?int {
            $value = (int) PersianText::toAsciiDigits(trim((string) $value));
            return $value > 0 && $value <= 10080 ? $value : null;
        };

        $ingredients = [];
        foreach (self::listOf($input['ingredients'] ?? []) as $row) {
            $name = self::text($row['name'] ?? '', 200, true);
            if ($name === '') {
                continue;
            }
            $ingredients[] = [
                'quantity' => self::quantity((string) ($row['quantity'] ?? '')),
                'unit'     => self::text($row['unit'] ?? '', 60, true),
                'name'     => $name,
                'note'     => self::text($row['note'] ?? '', 200, true),
            ];
        }

        $steps = [];
        foreach (self::listOf($input['steps'] ?? []) as $row) {
            $text = self::text(is_array($row) ? ($row['text'] ?? '') : $row, 1500, true);
            if ($text !== '') {
                $steps[] = ['text' => $text];
            }
        }

        if (count($ingredients) > self::LIMITS['ingredients'] || count($steps) > self::LIMITS['steps']) {
            $errors[] = 'At most ' . self::LIMITS['ingredients'] . ' ingredients and ' . self::LIMITS['steps'] . ' steps.';
        }

        $yield = (int) PersianText::toAsciiDigits(trim((string) ($input['yield_number'] ?? '')));

        return [
            'yield_number' => $yield > 0 && $yield <= 500 ? $yield : null,
            'yield_unit'   => self::text($input['yield_unit'] ?? 'نفر', 30, true) ?: 'نفر',
            'prep_minutes' => $minutes($input['prep_minutes'] ?? null),
            'cook_minutes' => $minutes($input['cook_minutes'] ?? null),
            'difficulty'   => self::text($input['difficulty'] ?? '', 40, true),
            'ingredients'  => array_slice($ingredients, 0, self::LIMITS['ingredients']),
            'steps'        => array_slice($steps, 0, self::LIMITS['steps']),
        ];
    }

    /**
     * An amount as a number when it is one, so the serving scaler can multiply
     * it: "2", "۲٫۵", "1/2", "۱ ۱/۲", "½". Anything else ("به‌اندازه لازم")
     * stays text.
     */
    public static function quantity(string $raw): int|float|string|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $ascii = str_replace(['٫', '،', '⁄'], ['.', '.', '/'], PersianText::toAsciiDigits($raw));
        $ascii = strtr($ascii, ['½' => ' 1/2', '⅓' => ' 1/3', '¼' => ' 1/4', '¾' => ' 3/4', '⅔' => ' 2/3']);
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? $ascii);

        $value = null;
        if (preg_match('/^\d+(\.\d+)?$/', $ascii) === 1) {
            $value = (float) $ascii;
        } elseif (preg_match('#^(?:(\d+) )?(\d+)/(\d+)$#', $ascii, $m) === 1 && (int) $m[3] > 0) {
            $value = (int) ($m[1] ?: 0) + (int) $m[2] / (int) $m[3];
        }

        if ($value === null) {
            return mb_substr(PersianText::display($raw), 0, 40, 'UTF-8');
        }

        $value = round($value, 3);

        return floor($value) == $value ? (int) $value : $value;
    }

    // -------------------------------------------------------------- rendering

    /**
     * The article body as HTML.
     *
     * @param array<int,array{path:string,width:?int,height:?int,alt_fa:?string}> $media by id, for image blocks
     */
    public static function render(array $doc, array $media = []): string
    {
        $html = self::renderBlocks($doc['intro'] ?? [], $media);

        foreach ($doc['sections'] ?? [] as $section) {
            $html .= '<h2>' . self::e((string) $section['heading']) . "</h2>\n";
            $html .= self::renderBlocks($section['blocks'] ?? [], $media);
        }

        return $html;
    }

    private static function renderBlocks(array $blocks, array $media): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $text = (string) ($block['text'] ?? '');

            $html .= match ($block['type'] ?? 'paragraph') {
                'subheading' => '<h3>' . self::e($text) . "</h3>\n",
                'list', 'ordered' => self::renderList($text, ($block['type'] ?? '') === 'ordered'),
                'tip', 'note', 'warning' => '<div class="' . $block['type'] . '">' . self::paragraphs($text) . "</div>\n",
                'quote' => '<blockquote>' . self::paragraphs($text) . "</blockquote>\n",
                'image' => self::renderImage($block, $media),
                default => self::paragraphs($text) . "\n",
            };
        }

        return $html;
    }

    private static function renderList(string $text, bool $ordered): string
    {
        $items = array_filter(array_map('trim', explode("\n", $text)), static fn(string $line) => $line !== '');
        $items = array_map(static fn(string $line) => preg_replace('/^(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? $line, $items);
        $tag = $ordered ? 'ol' : 'ul';

        return "<{$tag}>" . implode('', array_map(static fn(string $item) => '<li>' . self::inline($item) . '</li>', $items)) . "</{$tag}>\n";
    }

    private static function renderImage(array $block, array $media): string
    {
        $item = $media[(int) ($block['media_id'] ?? 0)] ?? null;
        if ($item === null || !UrlGuard::isMediaPath($item['path'] ?? null)) {
            return '';
        }

        $caption = (string) ($block['text'] ?? '');
        $alt = (string) (($item['alt_fa'] ?? '') ?: $caption);
        $size = !empty($item['width']) && !empty($item['height'])
            ? ' width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '"'
            : '';

        return '<figure><img src="/media/' . self::e((string) $item['path']) . '" alt="' . self::e($alt) . '"' . $size . ' loading="lazy">'
            . ($caption !== '' ? '<figcaption>' . self::inline($caption) . '</figcaption>' : '')
            . "</figure>\n";
    }

    /** Blank lines separate paragraphs; single line breaks are just spaces. */
    private static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\n\s*\n/u', $text) ?: [] as $paragraph) {
            $paragraph = trim(preg_replace('/\s*\n\s*/u', ' ', $paragraph) ?? $paragraph);
            if ($paragraph !== '') {
                $out .= '<p>' . self::inline($paragraph) . '</p>';
            }
        }

        return $out;
    }

    /** Escaped text with **bold** and citation markers — nothing else. */
    private static function inline(string $text): string
    {
        $html = self::e($text);
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html) ?? $html;

        return preg_replace_callback(self::CITE, static function (array $m): string {
            return implode('', array_map(
                static fn(int $n) => '<a class="cite" href="#ref-' . $n . '">' . PersianText::toPersianDigits((string) $n) . '</a>',
                self::markers($m[1])
            ));
        }, $html) ?? $html;
    }

    // ---------------------------------------------------------- for checking

    /**
     * The document in the shape CitationValidator reads: every block becomes
     * a paragraph whose refs are the markers it contains, and whose text has
     * the markers removed (so "[3]" is not mistaken for a figure).
     *
     * @return array{sections: list<array{heading:string, paragraphs:list<array{text:string, refs:list<int>}>}>}
     */
    public static function draft(array $doc): array
    {
        $sections = [];
        $groups = [['heading' => '', 'blocks' => $doc['intro'] ?? []], ...($doc['sections'] ?? [])];

        foreach ($groups as $group) {
            $paragraphs = [];
            foreach ($group['blocks'] ?? [] as $block) {
                if (in_array($block['type'] ?? '', ['subheading'], true)) {
                    continue;
                }
                $text = (string) ($block['text'] ?? '');
                $refs = [];
                if (preg_match_all(self::CITE, $text, $m)) {
                    foreach ($m[1] as $group1) {
                        $refs = [...$refs, ...self::markers($group1)];
                    }
                }
                $paragraphs[] = [
                    'text' => trim(preg_replace(self::CITE, '', str_replace('**', '', $text)) ?? $text),
                    'refs' => array_values(array_unique($refs)),
                ];
            }
            if ($paragraphs !== []) {
                $sections[] = ['heading' => (string) $group['heading'], 'paragraphs' => $paragraphs];
            }
        }

        return ['sections' => $sections];
    }

    /**
     * Sources in the shape CitationValidator reads, keyed by marker. The text
     * checked against is whatever is on record for the page plus the quote
     * the author pasted, so a figure an author backs with a quote verifies.
     *
     * @param array<string,string> $storedText url => extracted text already on record
     * @return array<int,array{url:string,extracted_text:string}>
     */
    public static function sources(array $doc, array $storedText = []): array
    {
        $sources = [];
        foreach (array_values($doc['references'] ?? []) as $i => $reference) {
            $sources[$i + 1] = [
                'url'            => (string) $reference['url'],
                'extracted_text' => trim(($storedText[$reference['url']] ?? '') . "\n" . ($reference['quote'] ?? '')),
            ];
        }

        return $sources;
    }

    /** recipe_json as ViewModels and the structured data expect it. */
    public static function recipeJson(array $doc): ?array
    {
        $recipe = $doc['recipe'] ?? null;
        if (!is_array($recipe)) {
            return null;
        }

        $total = (int) ($recipe['prep_minutes'] ?? 0) + (int) ($recipe['cook_minutes'] ?? 0);

        return [...$recipe, 'total_minutes' => $total > 0 ? $total : null];
    }

    /** Media ids the document uses, for loading them in one query. */
    public static function mediaIds(array $doc): array
    {
        $ids = [];
        foreach ([['blocks' => $doc['intro'] ?? []], ...($doc['sections'] ?? [])] as $group) {
            foreach ($group['blocks'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'image' && !empty($block['media_id'])) {
                    $ids[] = (int) $block['media_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<int> */
    private static function markers(string $inside): array
    {
        $numbers = preg_split('/\s*[,،]\s*/u', PersianText::toAsciiDigits($inside)) ?: [];

        return array_values(array_filter(array_map('intval', $numbers), static fn(int $n) => $n > 0));
    }

    /**
     * Author text, tidied: Arabic-keyboard letters folded to Persian for
     * display, control characters removed, length bounded. Newlines are kept
     * unless the field is single-line.
     */
    private static function text(mixed $value, int $max, bool $singleLine = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        // C0 controls other than newline and tab, DEL, and bidi overrides that
        // could make text display differently from how it reads.
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
        if ($singleLine) {
            $text = preg_replace('/\s*\n\s*/u', ' ', $text) ?? $text;
        }
        $text = PersianText::display(trim($text));

        return mb_substr($text, 0, $max, 'UTF-8');
    }

    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
