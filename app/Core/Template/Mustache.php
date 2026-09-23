<?php
declare(strict_types=1);

namespace App\Core\Template;

/**
 * A logic-less template engine: a strict subset of Mustache.
 *
 * Why not PHP templates for themes: a PHP template can do anything — query
 * the database, read config.local.php, write files. Themes are meant to be
 * rewritten freely, by people or by language models, without anyone reading
 * the backend. Logic-less templates make that safe by construction: a template
 * can only arrange the data it is given.
 *
 * Supported:
 *   {{name}}  {{a.b.c}}  {{.}}      values, HTML-escaped unless SafeHtml
 *   {{{name}}}  {{&name}}           accepted; identical to {{name}}
 *   {{#name}}…{{/name}}             section: list → once per item,
 *                                   object → once with it as context,
 *                                   truthy scalar → once
 *   {{^name}}…{{/name}}             inverted: renders when name is falsey
 *   {{>name}}                       partial from the theme's partials/
 *   {{! comment }}
 *
 * Deliberately absent: lambdas, set-delimiter, dynamic partial names, and any
 * way to emit unescaped HTML. Falsey means null, false, 0, "" or an empty
 * list, as in mustache.js.
 */
final class Mustache
{
    private const TAG = '/\{\{(\{)?\s*([#^\/>!&]?)\s*(.*?)\s*(\})?\}\}/s';

    /** @var array<string, list<array>> parsed partials, per engine instance */
    private array $partialCache = [];

    /**
     * @param \Closure(string):?string $loadPartial returns partial source or null
     */
    public function __construct(
        private readonly \Closure $loadPartial,
        private readonly int $maxDepth = 32,
    ) {}

    public function render(string $source, array $context, string $name = 'template'): string
    {
        return $this->renderNodes($this->parse($source, $name), [$context], 0, $name);
    }

    /**
     * Parse into a node tree. Errors carry the template name and line.
     *
     * @return list<array>
     */
    public function parse(string $source, string $name = 'template'): array
    {
        if (preg_match_all(self::TAG, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            throw new TemplateError("{$name}: could not scan template.");
        }

        $root = [];
        $stack = [];              // [nodeList reference, name, line]
        $current = &$root;
        $offset = 0;

        foreach ($matches as $match) {
            [$whole, $position] = $match[0];
            $triple = $match[1][0] === '{';
            $sigil = $match[2][0];
            $tagName = trim($match[3][0]);
            $closingBrace = ($match[4][0] ?? '') === '}';
            $line = substr_count($source, "\n", 0, $position) + 1;

            if ($position > $offset) {
                $current[] = ['text', substr($source, $offset, $position - $offset)];
            }
            $offset = $position + strlen($whole);

            if ($triple !== $closingBrace) {
                throw new TemplateError("{$name}, line {$line}: unbalanced braces in {$whole}");
            }

            switch ($sigil) {
                case '!':
                    break;

                case '#':
                case '^':
                    self::assertName($tagName, $name, $line);
                    $node = ['section', $tagName, [], $sigil === '^', $line];
                    $current[] = $node;
                    $stack[] = [&$current, $tagName, $line];
                    $current = &$current[array_key_last($current)][2];
                    break;

                case '/':
                    if ($stack === []) {
                        throw new TemplateError("{$name}, line {$line}: {{/{$tagName}}} closes nothing.");
                    }
                    $open = array_pop($stack);
                    if ($open[1] !== $tagName) {
                        throw new TemplateError(
                            "{$name}, line {$line}: {{/{$tagName}}} does not match {{#{$open[1]}}} opened on line {$open[2]}."
                        );
                    }
                    $current = &$open[0];
                    break;

                case '>':
                    if (preg_match('#^[a-z0-9][a-z0-9_\-/]*$#', $tagName) !== 1 || str_contains($tagName, '..')) {
                        throw new TemplateError("{$name}, line {$line}: invalid partial name \"{$tagName}\".");
                    }
                    $current[] = ['partial', $tagName, $line];
                    break;

                default:   // '' or '&'
                    self::assertName($tagName, $name, $line);
                    $current[] = ['var', $tagName];
            }
        }

        if ($stack !== []) {
            $open = end($stack);
            throw new TemplateError("{$name}, line {$open[2]}: {{#{$open[1]}}} is never closed.");
        }

        if ($offset < strlen($source)) {
            $current[] = ['text', substr($source, $offset)];
        }

        unset($current);

        return $root;
    }

    /** @param list<mixed> $stack context stack, innermost last */
    private function renderNodes(array $nodes, array $stack, int $depth, string $name): string
    {
        $out = '';

        foreach ($nodes as $node) {
            switch ($node[0]) {
                case 'text':
                    $out .= $node[1];
                    break;

                case 'var':
                    $out .= self::stringify(self::lookup($node[1], $stack));
                    break;

                case 'section':
                    $value = self::lookup($node[1], $stack);
                    $inverted = $node[3];

                    if ($inverted) {
                        if (self::isFalsey($value)) {
                            $out .= $this->renderNodes($node[2], $stack, $depth, $name);
                        }
                        break;
                    }

                    if (self::isFalsey($value)) {
                        break;
                    }

                    if (is_array($value) && array_is_list($value)) {
                        foreach ($value as $item) {
                            $out .= $this->renderNodes($node[2], [...$stack, $item], $depth, $name);
                        }
                    } else {
                        $out .= $this->renderNodes($node[2], [...$stack, $value], $depth, $name);
                    }
                    break;

                case 'partial':
                    if ($depth >= $this->maxDepth) {
                        throw new TemplateError("{$name}: partials nested deeper than {$this->maxDepth} (recursion without an end?).");
                    }
                    $out .= $this->renderNodes($this->partial($node[1], $node[2], $name), $stack, $depth + 1, $node[1]);
                    break;
            }
        }

        return $out;
    }

    private function partial(string $partialName, int $line, string $from): array
    {
        if (!isset($this->partialCache[$partialName])) {
            $source = ($this->loadPartial)($partialName);
            if ($source === null) {
                throw new TemplateError("{$from}, line {$line}: partial \"{$partialName}\" not found.");
            }
            $this->partialCache[$partialName] = $this->parse($source, 'partials/' . $partialName);
        }

        return $this->partialCache[$partialName];
    }

    /**
     * Resolve a name against the context stack.
     *
     * The first segment is searched from the innermost context outwards; the
     * remaining segments descend into whatever that found, with no further
     * searching — the Mustache rule, which keeps {{a.b}} from accidentally
     * picking up an unrelated "b" from an outer scope.
     */
    private static function lookup(string $name, array $stack): mixed
    {
        if ($name === '.') {
            return end($stack);
        }

        $segments = explode('.', $name);
        $first = array_shift($segments);
        $value = null;
        $found = false;

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $context = $stack[$i];
            if (is_array($context) && array_key_exists($first, $context)) {
                $value = $context[$first];
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function isFalsey(mixed $value): bool
    {
        return $value === null
            || $value === false
            || $value === 0
            || $value === 0.0
            || $value === ''
            || $value === [];
    }

    /** The one place output is produced. SafeHtml passes; everything else is escaped. */
    private static function stringify(mixed $value): string
    {
        if ($value instanceof SafeHtml) {
            return (string) $value;
        }

        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function assertName(string $tagName, string $template, int $line): void
    {
        if ($tagName !== '.' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/', $tagName) !== 1) {
            throw new TemplateError("{$template}, line {$line}: invalid name \"{$tagName}\".");
        }
    }
}
