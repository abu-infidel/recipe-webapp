<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Domain\ArticleComposer;
use App\Domain\CitationValidator;
use App\Support\HtmlSanitizer;

/**
 * Structured articles: what an author types is text, and only the composer
 * decides which tags it becomes.
 */
final class ArticleComposerTest extends TestCase
{
    public function run(): void {}

    private function doc(array $input): array
    {
        return ArticleComposer::normalize($input)['doc'];
    }

    // ---- normalising ------------------------------------------------------

    public function testNormalizeKeepsOnlyKnownShapeAndDropsEmptyRows(): void
    {
        $result = ArticleComposer::normalize([
            'evil'     => '<script>',
            'intro'    => [['type' => 'paragraph', 'text' => 'سلام', 'onclick' => 'x'], ['type' => 'paragraph', 'text' => '   ']],
            'sections' => [
                ['heading' => 'پخت', 'blocks' => [['type' => 'bogus', 'text' => 'متن']]],
                ['heading' => '', 'blocks' => []],
            ],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['format', 'intro', 'sections', 'references', 'recipe'], array_keys($result['doc']));
        $this->assertSame([['type' => 'paragraph', 'text' => 'سلام', 'media_id' => null]], $result['doc']['intro'], 'unknown keys and blank blocks gone');
        $this->assertSame(1, count($result['doc']['sections']), 'empty section row dropped');
        $this->assertSame('paragraph', $result['doc']['sections'][0]['blocks'][0]['type'], 'unknown block type becomes a paragraph');
    }

    public function testNormalizeReportsWhatMakesADocumentUnusable(): void
    {
        $errors = ArticleComposer::normalize([
            'sections'   => [['heading' => '', 'blocks' => [['type' => 'paragraph', 'text' => 'بی‌عنوان']]]],
            'references' => [['url' => 'javascript:alert(1)', 'title' => 'x']],
        ])['errors'];

        $this->assertStringContains('no heading', implode("\n", $errors));
        $this->assertStringContains('http:// or https://', implode("\n", $errors));

        $this->assertSame(['The article has no text.'], ArticleComposer::normalize([])['errors']);
        $this->assertSame(['The article has no text.'], ArticleComposer::normalize('not an array')['errors']);
    }

    public function testNormalizeFoldsArabicKeyboardLettersAndStripsControls(): void
    {
        $doc = $this->doc(['intro' => [['text' => "كتاب ي\u{202E}evil\u{0007}"]]]);

        $this->assertSame('کتاب یevil', $doc['intro'][0]['text']);
    }

    public function testLimitsAreEnforced(): void
    {
        $blocks = array_fill(0, 70, ['type' => 'paragraph', 'text' => 'x']);
        $result = ArticleComposer::normalize(['intro' => $blocks]);

        $this->assertSame(ArticleComposer::LIMITS['blocks'], count($result['doc']['intro']));
        $this->assertStringContains('at most', implode("\n", $result['errors']));
    }

    // ---- rendering --------------------------------------------------------

    public function testTextIsAlwaysEscaped(): void
    {
        $html = ArticleComposer::render($this->doc([
            'intro' => [['text' => '<script>alert(1)</script> & <b onclick="x">']],
            'sections' => [['heading' => '<img src=x onerror=alert(1)>', 'blocks' => [['type' => 'list', 'text' => "<i>a</i>\nb"]]]],
        ]));

        $this->assertStringNotContains('<script', $html);
        $this->assertStringNotContains('<img', $html);
        $this->assertStringNotContains('<b ', $html);
        $this->assertStringNotContains('<i>', $html);
        $this->assertStringContains('&lt;script&gt;', $html);
    }

    public function testInlineBoldAndCitations(): void
    {
        $html = ArticleComposer::render($this->doc(['intro' => [['text' => 'برنج **ایرانی** [1] و [۲، 3] و [ ۴ ].']]]));

        $this->assertStringContains('<strong>ایرانی</strong>', $html);
        $this->assertStringContains('<a class="cite" href="#ref-1">۱</a>', $html);
        $this->assertStringContains('<a class="cite" href="#ref-2">۲</a><a class="cite" href="#ref-3">۳</a>', $html);
        $this->assertStringContains('<a class="cite" href="#ref-4">۴</a>', $html);
    }

    public function testBlocksBecomeTheRightElements(): void
    {
        $html = ArticleComposer::render($this->doc([
            'intro' => [['text' => "بند اول\nادامه\n\nبند دوم"]],
            'sections' => [['heading' => 'بخش', 'blocks' => [
                ['type' => 'subheading', 'text' => 'زیربخش'],
                ['type' => 'list', 'text' => "- یک\n• دو\n\nسه"],
                ['type' => 'ordered', 'text' => "1. اول\n2) دوم"],
                ['type' => 'tip', 'text' => 'نکته'],
                ['type' => 'warning', 'text' => 'هشدار'],
                ['type' => 'quote', 'text' => 'نقل'],
            ]]],
        ]));

        $this->assertStringContains('<p>بند اول ادامه</p><p>بند دوم</p>', $html, 'single newline joins, blank line splits');
        $this->assertStringContains('<h2>بخش</h2>', $html);
        $this->assertStringContains('<h3>زیربخش</h3>', $html);
        $this->assertStringContains('<ul><li>یک</li><li>دو</li><li>سه</li></ul>', $html, 'typed bullets removed');
        $this->assertStringContains('<ol><li>اول</li><li>دوم</li></ol>', $html, 'typed numbers removed');
        $this->assertStringContains('<div class="tip"><p>نکته</p></div>', $html);
        $this->assertStringContains('<div class="warning">', $html);
        $this->assertStringContains('<blockquote><p>نقل</p></blockquote>', $html);
    }

    public function testImagesComeOnlyFromTheMediaLibrary(): void
    {
        $doc = $this->doc(['intro' => [
            ['type' => 'image', 'media_id' => 7, 'text' => 'کته'],
            ['type' => 'image', 'media_id' => 8, 'text' => 'bad path'],
            ['type' => 'image', 'media_id' => 9, 'text' => 'missing'],
            ['type' => 'paragraph', 'text' => 'x'],
        ]]);
        $html = ArticleComposer::render($doc, [
            7 => ['path' => '2026/09/abc-960.webp', 'width' => 960, 'height' => 640, 'alt_fa' => ''],
            8 => ['path' => '../../app/config.local.php', 'width' => 1, 'height' => 1, 'alt_fa' => ''],
        ]);

        $this->assertStringContains('<figure><img src="/media/2026/09/abc-960.webp" alt="کته" width="960" height="640" loading="lazy"><figcaption>کته</figcaption></figure>', $html);
        $this->assertStringNotContains('config.local', $html, 'a path outside media is dropped');
        $this->assertSame(1, substr_count($html, '<figure>'), 'unknown media renders nothing');
        $this->assertSame([7, 8, 9], ArticleComposer::mediaIds($doc));
    }

    public function testOutputSurvivesTheSanitizerUnchanged(): void
    {
        $doc = $this->doc([
            'intro' => [['text' => 'مقدمه **مهم** [1]']],
            'sections' => [['heading' => 'بخش', 'blocks' => [
                ['type' => 'list', 'text' => "یک\nدو [2]"], ['type' => 'note', 'text' => 'یادداشت'],
                ['type' => 'quote', 'text' => 'نقل'], ['type' => 'image', 'media_id' => 1, 'text' => 'عکس'],
            ]]],
            'references' => [['url' => 'https://a.example/1', 'title' => 'a'], ['url' => 'https://b.example/2', 'title' => 'b']],
        ]);
        $html = ArticleComposer::render($doc, [1 => ['path' => 'a/b-480.webp', 'width' => 480, 'height' => 320, 'alt_fa' => 'x']]);

        $this->assertSame(
            preg_replace('/\s+/', ' ', trim($html)),
            preg_replace('/\s+/', ' ', trim(HtmlSanitizer::clean($html))),
            'nothing the composer emits is stripped at publish'
        );
    }

    // ---- checking ---------------------------------------------------------

    public function testDraftCarriesRefsAndDropsMarkersFromText(): void
    {
        $draft = ArticleComposer::draft($this->doc([
            'intro' => [['text' => 'آب ۱۸۰ میلی‌لیتر [2]']],
            'sections' => [['heading' => 'الف', 'blocks' => [['type' => 'subheading', 'text' => 'ب'], ['type' => 'list', 'text' => "x [1]\ny [۳]"]]]],
        ]));

        $this->assertSame('', $draft['sections'][0]['heading']);
        $this->assertSame(['text' => 'آب ۱۸۰ میلی‌لیتر', 'refs' => [2]], $draft['sections'][0]['paragraphs'][0]);
        $this->assertSame(['text' => '', 'refs' => []], $draft['sections'][1]['paragraphs'][0], 'a subheading keeps its slot but is not a claim');
        $this->assertSame([1, 3], $draft['sections'][1]['paragraphs'][1]['refs']);

        $noIntro = ArticleComposer::draft($this->doc(['sections' => [['heading' => 'الف', 'blocks' => [['text' => 'x [1]']]]]]));
        $this->assertSame([], $noIntro['sections'][0]['paragraphs'], 'an empty introduction still counts as s1');
        $this->assertSame([1], $noIntro['sections'][1]['paragraphs'][0]['refs']);
    }

    public function testValidatorCatchesInventedReferencesAndUsesTheQuote(): void
    {
        $doc = $this->doc([
            'intro' => [
                ['text' => 'نسبت آب به برنج ۱٫۵ به ۱ است [1].'],
                ['text' => 'دمای روغن ۱۸۰ درجه [2].'],
                ['text' => 'زمان دم ۳۵ دقیقه [1].'],
            ],
            'references' => [
                ['url' => 'https://example.org/rice', 'title' => 'Rice', 'quote' => 'Use 1.5 cups of water per cup of rice.'],
            ],
        ]);

        $findings = CitationValidator::validate(ArticleComposer::draft($doc), ArticleComposer::sources($doc))['findings'];
        $codes = array_map(static fn(array $f) => $f['code'] . '@' . $f['anchor'], $findings);

        $this->assertContains('unknown_source@s1p2', $codes, '[2] does not exist');
        $this->assertNotContains('unsupported_number@s1p1', $codes, '1.5 is backed by the quote');
        $this->assertContains('unsupported_number@s1p3', $codes, '35 is not in the quote');
    }

    public function testSourcesMergeStoredTextWithTheQuote(): void
    {
        $doc = $this->doc(['intro' => [['text' => 'x']], 'references' => [['url' => 'https://a.example/', 'title' => 't', 'quote' => 'q']]]);

        $this->assertSame(
            [1 => ['url' => 'https://a.example/', 'extracted_text' => "fetched\nq"]],
            ArticleComposer::sources($doc, ['https://a.example/' => 'fetched'])
        );
    }

    // ---- recipes ----------------------------------------------------------

    public function testQuantitiesBecomeNumbersWhenTheyAreNumbers(): void
    {
        $cases = [
            '2' => 2, '۲' => 2, '۲٫۵' => 2.5, '2.5' => 2.5, '1/2' => 0.5, '۱/۲' => 0.5,
            '1 1/2' => 1.5, '۱ ۱/۲' => 1.5, '½' => 0.5, '1½' => 1.5, '' => null,
            'به‌اندازه لازم' => 'به‌اندازه لازم', '2-3' => '2-3',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, ArticleComposer::quantity((string) $input), "quantity \"{$input}\"");
        }
    }

    public function testRecipeIsNormalisedAndTotalled(): void
    {
        $doc = $this->doc([
            'intro' => [['text' => 'x']],
            'recipe' => [
                'yield_number' => '۴', 'prep_minutes' => '10', 'cook_minutes' => '۴۵', 'difficulty' => 'آسان',
                'ingredients' => [['quantity' => '۳', 'unit' => 'پیمانه', 'name' => 'برنج'], ['quantity' => '1', 'name' => '']],
                'steps' => [['text' => 'بشویید.'], ['text' => ' ']],
            ],
        ]);
        $recipe = ArticleComposer::recipeJson($doc);

        $this->assertSame(4, $recipe['yield_number']);
        $this->assertSame(55, $recipe['total_minutes']);
        $this->assertSame(1, count($recipe['ingredients']), 'nameless ingredient dropped');
        $this->assertSame(3, $recipe['ingredients'][0]['quantity']);
        $this->assertSame([['text' => 'بشویید.']], $recipe['steps']);
        $this->assertSame(null, ArticleComposer::recipeJson($this->doc(['intro' => [['text' => 'x']]])), 'guides have no recipe');
    }
}
