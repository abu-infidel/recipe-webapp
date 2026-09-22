<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Turns known topic phrases into internal links, the way a wiki does.
 *
 * Runs at publish time, never on a request. When a new article is published
 * the older ones are re-linked in the background, so the site grows denser by
 * itself without anyone editing old text.
 *
 * The rules that matter:
 *   - only text nodes are touched; never attributes, never markup
 *   - never inside a heading, an existing link, code, or the references block
 *   - longest alias wins, so "خورش قیمه بادمجان" beats "قیمه"
 *   - each target is linked a bounded number of times per article (once by
 *     default), because a page where every other word is blue is unreadable
 *   - an article never links to itself
 */
final class AutoLinker
{
    /** Elements whose text must be left alone. */
    private const SKIP_TAGS = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'figcaption'];

    /**
     * @param array<string,array{url:string,title:string,article_id:int}> $aliases
     *        normalised alias => target
     */
    public function __construct(
        private readonly array $aliases,
        private readonly int $maxPerTarget = 1,
        private readonly ?int $selfArticleId = null,
    ) {}

    /**
     * Build a linker from the topic_aliases table.
     *
     * @param list<array{alias:string,alias_norm:string,article_id:int,slug:string,field_path:string,title_fa:string}> $rows
     */
    public static function fromRows(array $rows, int $maxPerTarget = 1, ?int $selfArticleId = null): self
    {
        $aliases = [];

        foreach ($rows as $row) {
            $normalised = (string) $row['alias_norm'];
            if ($normalised === '') {
                continue;
            }

            $aliases[$normalised] = [
                'url'        => \App\Core\Url::article((string) $row['field_path'], (string) $row['slug']),
                'title'      => (string) $row['title_fa'],
                'article_id' => (int) $row['article_id'],
            ];
        }

        // Longest first, so a specific phrase is matched before a shorter one
        // contained inside it.
        uksort($aliases, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        return new self($aliases, $maxPerTarget, $selfArticleId);
    }

    public function apply(string $html): string
    {
        if ($this->aliases === [] || trim($html) === '') {
            return $html;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // The XML declaration is what makes libxml treat the input as UTF-8;
        // without it Persian text is mangled into Latin-1.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . '<div id="autolink-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $root = $document->getElementById('autolink-root');
        if ($root === null) {
            return $html;
        }

        $used = [];
        $this->walk($root, $document, $used);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param array<int,int> $used article_id => times linked so far
     */
    private function walk(\DOMNode $node, \DOMDocument $document, array &$used): void
    {
        if ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->nodeName), self::SKIP_TAGS, true)) {
                return;
            }
            // The references block is generated and must stay untouched.
            if ($node->getAttribute('id') === 'references' || $node->getAttribute('class') === 'references') {
                return;
            }
        }

        // Snapshot the children: replacing a text node mutates the live list.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMText) {
                $this->linkTextNode($child, $document, $used);
            } else {
                $this->walk($child, $document, $used);
            }
        }
    }

    private function linkTextNode(\DOMText $textNode, \DOMDocument $document, array &$used): void
    {
        $text = $textNode->nodeValue ?? '';
        if (trim($text) === '') {
            return;
        }

        foreach ($this->aliases as $normalised => $target) {
            if ($target['article_id'] === $this->selfArticleId) {
                continue;
            }
            if (($used[$target['article_id']] ?? 0) >= $this->maxPerTarget) {
                continue;
            }

            $offset = $this->findAlias($text, $normalised);
            if ($offset === null) {
                continue;
            }

            [$start, $length] = $offset;

            $before = mb_substr($text, 0, $start, 'UTF-8');
            $matched = mb_substr($text, $start, $length, 'UTF-8');
            $after = mb_substr($text, $start + $length, null, 'UTF-8');

            $link = $document->createElement('a');
            $link->setAttribute('href', $target['url']);
            $link->setAttribute('data-internal', '1');
            $link->setAttribute('title', $target['title']);
            $link->appendChild($document->createTextNode($matched));

            $parent = $textNode->parentNode;
            if ($parent === null) {
                return;
            }

            // Replace the text node with [before, <a>, after] in one go.
            $fragment = $document->createDocumentFragment();
            if ($before !== '') {
                $fragment->appendChild($document->createTextNode($before));
            }
            $fragment->appendChild($link);
            if ($after !== '') {
                $fragment->appendChild($document->createTextNode($after));
            }
            $parent->replaceChild($fragment, $textNode);

            $used[$target['article_id']] = ($used[$target['article_id']] ?? 0) + 1;

            return;   // one link per text node keeps the result readable
        }
    }

    /**
     * Locate a normalised alias inside raw text, returning the offset and
     * length *in the raw text* so the link lands on the original characters.
     *
     * @return array{0:int,1:int}|null
     */
    private function findAlias(string $text, string $normalisedAlias): ?array
    {
        [$normalised, $map] = PersianText::normalizeWithMap($text);

        $position = mb_strpos($normalised, $normalisedAlias, 0, 'UTF-8');
        if ($position === false) {
            return null;
        }

        $aliasLength = mb_strlen($normalisedAlias, 'UTF-8');

        // Without a boundary check, "قیمه" would match inside "قیمه‌ای" and
        // produce a link over half a word.
        if (!$this->isWordBoundary($normalised, $position, $aliasLength)) {
            return null;
        }

        $startIndex = $map[$position] ?? null;
        $endIndex = $map[$position + $aliasLength - 1] ?? null;

        if ($startIndex === null || $endIndex === null) {
            return null;
        }

        return [$startIndex, $endIndex - $startIndex + 1];
    }

    private function isWordBoundary(string $haystack, int $position, int $length): bool
    {
        $before = $position > 0 ? mb_substr($haystack, $position - 1, 1, 'UTF-8') : ' ';
        $after = mb_substr($haystack, $position + $length, 1, 'UTF-8');

        $isSeparator = static fn(string $c): bool => $c === '' || $c === ' ';

        return $isSeparator($before) && $isSeparator($after);
    }
}
