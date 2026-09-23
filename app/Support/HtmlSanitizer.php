<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Allow-list HTML sanitiser for article bodies.
 *
 * Article HTML comes out of the AI pipeline and is then editable by hand in
 * the admin panel, so it is never trusted. Sanitising happens once at publish
 * time; the stored body_html is what gets served, so a request never pays for
 * this.
 *
 * Allow-list, never deny-list: anything not named here is dropped, so a tag
 * or attribute nobody thought about cannot slip through.
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => [], 'cite' => [],
        'code' => [], 'pre' => [], 'kbd' => [], 'samp' => [],
        'a' => ['href', 'title', 'rel', 'target', 'class', 'data-internal'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['scope', 'colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'figure' => [], 'figcaption' => [],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'span' => ['class'], 'div' => ['class'],
        'dl' => [], 'dt' => [], 'dd' => [],
        'section' => ['id', 'class'], 'small' => [], 'mark' => [],
    ];

    /** Class names a body is allowed to carry. Anything else is stripped. */
    private const ALLOWED_CLASSES = ['cite', 'note', 'warning', 'tip', 'references', 'ingredients', 'steps'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Without the XML declaration libxml assumes Latin-1 and mangles
        // Persian into mojibake.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . '<div id="sanitize-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Unparseable input is returned as escaped text rather than
            // dropped, so a broken draft is visible instead of silently empty.
            return '<p>' . htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $root = $document->getElementById('sanitize-root');
        if ($root === null) {
            return '';
        }

        self::scrub($root, $document);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private static function scrub(\DOMNode $node, \DOMDocument $document): void
    {
        // Snapshot: unwrapping a node mutates the live child list.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;   // text nodes are safe; saveHTML escapes them
            }

            $tag = strtolower($child->nodeName);

            if (!isset(self::ALLOWED[$tag])) {
                // Unwrap rather than delete: a stray <font> should lose the
                // tag, not the sentence inside it. Script and style are the
                // exception — their text is not content.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form'], true)) {
                    $child->parentNode?->removeChild($child);
                } else {
                    self::scrub($child, $document);
                    self::unwrap($child);
                }
                continue;
            }

            self::scrubAttributes($child, $tag);

            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            self::scrub($child, $document);
        }
    }

    private static function scrubAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[] = $attribute->nodeName;
        }

        foreach ($attributes as $name) {
            $lower = strtolower($name);

            if (!in_array($lower, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }

            $value = $element->getAttribute($name);

            if ($lower === 'href') {
                if (!self::isSafeUrl($value)) {
                    $element->removeAttribute($name);
                }
            } elseif ($lower === 'src') {
                // Images come from this site's own media directory and nowhere
                // else. A remote image is a tracking pixel, and a request to a
                // foreign server that an international blackout would break.
                if (!self::isLocalMedia($value)) {
                    $element->removeAttribute($name);
                }
            } elseif ($lower === 'class') {
                $keep = array_values(array_intersect(
                    preg_split('/\s+/', trim($value)) ?: [],
                    self::ALLOWED_CLASSES
                ));

                if ($keep === []) {
                    $element->removeAttribute('class');
                } else {
                    $element->setAttribute('class', implode(' ', $keep));
                }
            }
        }

        // Any link leaving the site gets rel protection, whatever the source
        // HTML said.
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = $element->getAttribute('href');
            if (preg_match('#^https?://#i', $href) === 1) {
                $element->setAttribute('rel', 'nofollow noopener');
                $element->setAttribute('target', '_blank');
            }
        }
    }

    /**
     * Only http(s), site-relative paths and fragments. This is what blocks
     * javascript: and data: URLs.
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    private static function isLocalMedia(string $src): bool
    {
        return preg_match('#^/media/[A-Za-z0-9][A-Za-z0-9/_\-.]*$#', $src) === 1
            && !str_contains($src, '..');
    }

    /** Replace an element with its children. */
    private static function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
