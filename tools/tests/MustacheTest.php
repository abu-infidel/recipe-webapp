<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Template\Mustache;
use App\Core\Template\SafeHtml;
use App\Core\Template\TemplateError;

final class MustacheTest extends TestCase
{
    public function run(): void {}

    private function engine(array $partials = []): Mustache
    {
        return new Mustache(static fn(string $name) => $partials[$name] ?? null);
    }

    private function render(string $template, array $context = [], array $partials = []): string
    {
        return $this->engine($partials)->render($template, $context);
    }

    private function throws(callable $fn): ?string
    {
        try {
            $fn();
        } catch (TemplateError $e) {
            return $e->getMessage();
        }
        return null;
    }

    // ---- variables and escaping -----------------------------------------

    public function testRendersAVariable(): void
    {
        $this->assertSame('سلام دنیا', $this->render('سلام {{name}}', ['name' => 'دنیا']));
    }

    public function testEscapesHtmlInVariables(): void
    {
        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $this->render('{{v}}', ['v' => '<script>x</script>']));
    }

    public function testEscapesQuotesSoAttributesCannotBeBrokenOutOf(): void
    {
        $this->assertSame('<a title="&quot; onclick=&quot;x">', $this->render('<a title="{{t}}">', ['t' => '" onclick="x']));
    }

    public function testTripleMustacheStillEscapesPlainStrings(): void
    {
        // The whole point: a theme cannot opt out of escaping.
        $this->assertSame('&lt;b&gt;', $this->render('{{{v}}}', ['v' => '<b>']));
        $this->assertSame('&lt;b&gt;', $this->render('{{&v}}', ['v' => '<b>']));
    }

    public function testSafeHtmlIsRenderedRawWithAnySyntax(): void
    {
        $context = ['body' => new SafeHtml('<p>متن</p>')];

        $this->assertSame('<p>متن</p>', $this->render('{{body}}', $context));
        $this->assertSame('<p>متن</p>', $this->render('{{{body}}}', $context));
    }

    public function testMissingValuesRenderAsEmpty(): void
    {
        $this->assertSame('[]', $this->render('[{{nope}}]'));
        $this->assertSame('[]', $this->render('[{{a.b.c}}]', ['a' => ['x' => 1]]));
    }

    public function testArraysRenderAsEmptyRatherThanArray(): void
    {
        $this->assertSame('[]', $this->render('[{{list}}]', ['list' => [1, 2]]));
    }

    public function testNumbersAndBooleans(): void
    {
        $this->assertSame('3|true|false', $this->render('{{n}}|{{t}}|{{f}}', ['n' => 3, 't' => true, 'f' => false]));
    }

    public function testDottedNames(): void
    {
        $this->assertSame('قورمه', $this->render('{{article.title}}', ['article' => ['title' => 'قورمه']]));
    }

    // ---- sections ------------------------------------------------------

    public function testSectionIteratesAList(): void
    {
        $this->assertSame('<li>الف</li><li>ب</li>', $this->render(
            '{{#items}}<li>{{name}}</li>{{/items}}',
            ['items' => [['name' => 'الف'], ['name' => 'ب']]]
        ));
    }

    public function testImplicitIterator(): void
    {
        $this->assertSame('1,2,3,', $this->render('{{#n}}{{.}},{{/n}}', ['n' => [1, 2, 3]]));
    }

    public function testSectionWithObjectPushesContext(): void
    {
        $this->assertSame('قورمه', $this->render('{{#article}}{{title}}{{/article}}', ['article' => ['title' => 'قورمه']]));
    }

    public function testFalseyValuesSkipTheSection(): void
    {
        foreach ([null, false, 0, '', []] as $falsey) {
            $this->assertSame('', $this->render('{{#v}}shown{{/v}}', ['v' => $falsey]), 'must skip for ' . var_export($falsey, true));
        }
    }

    public function testInvertedSectionRendersWhenFalsey(): void
    {
        $this->assertSame('empty', $this->render('{{^items}}empty{{/items}}', ['items' => []]));
        $this->assertSame('', $this->render('{{^items}}empty{{/items}}', ['items' => [1]]));
    }

    public function testNestedSectionsOfTheSameName(): void
    {
        // The parser tracks open sections on a stack; mismatched pairing here
        // would silently produce the wrong nesting.
        $tree = ['children' => [
            ['t' => 'a', 'children' => [['t' => 'a1', 'children' => []]]],
            ['t' => 'b', 'children' => []],
        ]];
        $this->assertSame(
            '[a[a1]][b]',
            $this->render('{{#children}}[{{t}}{{#children}}[{{t}}]{{/children}}]{{/children}}', $tree)
        );
    }

    public function testDeeplyNestedDifferentSections(): void
    {
        $this->assertSame('ABC', $this->render('{{#a}}A{{#b}}B{{#c}}C{{/c}}{{/b}}{{/a}}', ['a' => true, 'b' => true, 'c' => true]));
    }

    public function testTextAfterNestedSectionsIsKept(): void
    {
        $this->assertSame('x-y-z', $this->render('x{{#a}}-{{#b}}y{{/b}}-{{/a}}z', ['a' => true, 'b' => true]));
    }

    public function testNamesResolveOutwardsThroughTheContextStack(): void
    {
        $this->assertSame('ctx:site', $this->render('{{#items}}{{label}}:{{siteName}}{{/items}}', [
            'siteName' => 'site',
            'items' => [['label' => 'ctx']],
        ]));
    }

    public function testDottedLookupDoesNotSearchOuterScopesForLaterSegments(): void
    {
        // {{a.b}} where the inner "a" lacks "b" must be empty, not fall back.
        $this->assertSame('[]', $this->render('{{#inner}}[{{a.b}}]{{/inner}}', [
            'a' => ['b' => 'outer'],
            'inner' => ['a' => ['x' => 1]],
        ]));
    }

    // ---- partials ------------------------------------------------------

    public function testRendersAPartialWithTheCurrentContext(): void
    {
        $this->assertSame('<h1>عنوان</h1>', $this->render('{{>head}}', ['t' => 'عنوان'], ['head' => '<h1>{{t}}</h1>']));
    }

    public function testRecursivePartialRendersATree(): void
    {
        $partials = ['node' => '<li>{{t}}{{#children}}<ul>{{>node}}</ul>{{/children}}</li>'];
        $out = $this->render('<ul>{{#tree}}{{>node}}{{/tree}}</ul>', ['tree' => [
            ['t' => 'a', 'children' => [['t' => 'a1', 'children' => []]]],
        ]], $partials);

        $this->assertSame('<ul><li>a<ul><li>a1</li></ul></li></ul>', $out);
    }

    public function testRunawayRecursionIsStopped(): void
    {
        $message = $this->throws(fn() => $this->render('{{>loop}}', [], ['loop' => 'x{{>loop}}']));
        $this->assertTrue($message !== null && str_contains($message, 'deeper'), 'infinite partial recursion must error, not hang');
    }

    public function testPartialNamesCannotTraverse(): void
    {
        $message = $this->throws(fn() => $this->render('{{>../../app/config}}'));
        $this->assertTrue($message !== null && str_contains($message, 'invalid partial name'));
    }

    public function testMissingPartialNamesItself(): void
    {
        $message = $this->throws(fn() => $this->render("a\n{{>nope}}"));
        $this->assertTrue($message !== null && str_contains($message, 'nope') && str_contains($message, 'line 2'));
    }

    // ---- errors a theme author will hit -----------------------------------

    public function testUnclosedSectionReportsWhereItOpened(): void
    {
        $message = $this->throws(fn() => $this->render("a\nb\n{{#items}}x"));
        $this->assertTrue($message !== null && str_contains($message, 'line 3') && str_contains($message, 'never closed'));
    }

    public function testMismatchedCloseIsReported(): void
    {
        $message = $this->throws(fn() => $this->render('{{#a}}{{/b}}'));
        $this->assertTrue($message !== null && str_contains($message, 'does not match'));
    }

    public function testStrayCloseIsReported(): void
    {
        $message = $this->throws(fn() => $this->render('{{/a}}'));
        $this->assertTrue($message !== null && str_contains($message, 'closes nothing'));
    }

    public function testCommentsProduceNothing(): void
    {
        $this->assertSame('ab', $this->render('a{{! anything, even { single } braces }}b'));
    }

    public function testCommentEndsAtTheFirstClosingDelimiter(): void
    {
        // As in the Mustache spec: a comment cannot contain "}}".
        $this->assertSame('a rest}}b', $this->render('a{{! stops at the first }} rest}}b'));
    }

    public function testInvalidNamesAreRejected(): void
    {
        foreach (['{{a b}}', '{{1abc}}', '{{a..b}}', '{{#x-y}}{{/x-y}}'] as $bad) {
            $this->assertTrue($this->throws(fn() => $this->render($bad)) !== null, 'must reject ' . $bad);
        }
    }

    public function testTextWithoutTagsIsUntouched(): void
    {
        $this->assertSame("<p>متن\nساده</p>", $this->render("<p>متن\nساده</p>"));
    }

    public function testSingleBracesAreLiteral(): void
    {
        // CSS and JSON in a template must survive.
        $this->assertSame('a { color: red; } {"k":1}', $this->render('a { color: red; } {"k":1}'));
    }
}
