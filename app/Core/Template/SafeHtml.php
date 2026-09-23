<?php
declare(strict_types=1);

namespace App\Core\Template;

/**
 * HTML that has already been made safe by core code.
 *
 * The template engine renders a SafeHtml value as-is and escapes everything
 * else, always, whichever tag syntax the template uses. That is the entire
 * XSS boundary for themes: a theme author (person or model) cannot output raw
 * HTML, because only core code can construct one of these.
 *
 * Sources of SafeHtml: article bodies (sanitised at publish), search snippets
 * (escaped before <mark> is added), the SEO <head> block and core script tags.
 */
final class SafeHtml implements \JsonSerializable, \Stringable
{
    public function __construct(private readonly string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }

    /** In the JSON API a SafeHtml value is simply its HTML string. */
    public function jsonSerialize(): string
    {
        return $this->html;
    }
}
