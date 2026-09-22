<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Core\Config;
use App\Support\AutoLinker;
use App\Support\PersianText;

final class AutoLinkerTest extends TestCase
{
    public function run(): void {}

    private function linker(int $maxPerTarget = 1, ?int $self = null): AutoLinker
    {
        // Config is needed because fromRows() builds absolute URLs.
        if (!self::$configured) {
            Config::load(__DIR__ . '/../../app/config.php');
            Config::set('site.domain', 'example.ir');
            Config::set('site.scheme', 'https');
            self::$configured = true;
        }

        return AutoLinker::fromRows([
            ['alias' => 'قورمه سبزی', 'alias_norm' => PersianText::normalize('قورمه سبزی'),
             'article_id' => 1, 'slug' => 'قورمه-سبزی', 'field_path' => 'ashpazi/khoresh', 'title_fa' => 'قورمه سبزی'],
            ['alias' => 'قیمه', 'alias_norm' => PersianText::normalize('قیمه'),
             'article_id' => 2, 'slug' => 'قیمه', 'field_path' => 'ashpazi/khoresh', 'title_fa' => 'قیمه'],
            ['alias' => 'خورش قیمه بادمجان', 'alias_norm' => PersianText::normalize('خورش قیمه بادمجان'),
             'article_id' => 3, 'slug' => 'قیمه-بادمجان', 'field_path' => 'ashpazi/khoresh', 'title_fa' => 'خورش قیمه بادمجان'],
        ], $maxPerTarget, $self);
    }

    private static bool $configured = false;

    public function testLinksAKnownPhrase(): void
    {
        $out = $this->linker()->apply('<p>امروز قورمه سبزی پختیم.</p>');

        $this->assertStringContains('data-internal="1"', $out);
        $this->assertStringContains('قورمه سبزی</a>', $out);
    }

    public function testDoesNotLinkInsideAHeading(): void
    {
        $out = $this->linker()->apply('<h2>قورمه سبزی</h2>');
        $this->assertStringNotContains('<a', $out, 'a heading must never become a link');
    }

    public function testDoesNotLinkInsideAnExistingLink(): void
    {
        $out = $this->linker()->apply('<p><a href="/x">قورمه سبزی</a></p>');
        $this->assertSame(1, substr_count($out, '<a '), 'must not nest a link inside a link');
    }

    public function testDoesNotLinkInsideCode(): void
    {
        $out = $this->linker()->apply('<p><code>قورمه سبزی</code></p>');
        $this->assertStringNotContains('data-internal', $out);
    }

    public function testLongestAliasWins(): void
    {
        // "قیمه" is contained in "خورش قیمه بادمجان"; the specific one must win.
        $out = $this->linker()->apply('<p>دستور خورش قیمه بادمجان را ببینید.</p>');

        $this->assertStringContains('خورش قیمه بادمجان</a>', $out);
        $this->assertSame(1, substr_count($out, '<a '), 'only one link, not a nested pair');
    }

    public function testLinksEachTargetOnlyOnceByDefault(): void
    {
        $out = $this->linker()->apply('<p>قورمه سبزی خوب است.</p><p>قورمه سبزی عالی است.</p>');
        $this->assertSame(1, substr_count($out, 'data-internal'));
    }

    public function testMaxPerTargetIsRespected(): void
    {
        $out = $this->linker(2)->apply('<p>قورمه سبزی</p><p>قورمه سبزی</p><p>قورمه سبزی</p>');
        $this->assertSame(2, substr_count($out, 'data-internal'));
    }

    public function testArticleNeverLinksToItself(): void
    {
        $out = $this->linker(1, 1)->apply('<p>قورمه سبزی غذای خوبی است.</p>');
        $this->assertStringNotContains('data-internal', $out);
    }

    public function testMatchesAcrossCharacterVariants(): void
    {
        // Written with an Arabic yeh; the alias is stored with a Persian one.
        $out = $this->linker()->apply('<p>امروز قورمه سبزي پختیم.</p>');
        $this->assertStringContains('data-internal', $out, 'Arabic-keyboard spelling must still match');
    }

    public function testDoesNotMatchInsideALongerWord(): void
    {
        // "قیمه" must not match inside "قیمهای" — that is a different word.
        $out = $this->linker()->apply('<p>قیمهای عجیب</p>');
        $this->assertStringNotContains('data-internal', $out);
    }

    public function testPreservesSurroundingText(): void
    {
        $out = $this->linker()->apply('<p>دیروز قورمه سبزی خوردیم.</p>');

        $this->assertStringContains('دیروز ', $out);
        $this->assertStringContains(' خوردیم.', $out);
    }

    public function testPreservesMarkupItDoesNotTouch(): void
    {
        $out = $this->linker()->apply('<p>متن <strong>پررنگ</strong> و قورمه سبزی</p>');

        $this->assertStringContains('<strong>پررنگ</strong>', $out);
        $this->assertStringContains('data-internal', $out);
    }

    public function testPersianTextIsNotMangled(): void
    {
        // libxml defaults to Latin-1 and will corrupt Persian unless the
        // encoding is declared; this is the regression guard for that.
        $out = $this->linker()->apply('<p>نان سنگک و کته ایرانی</p>');

        $this->assertStringContains('نان سنگک', $out);
        $this->assertStringContains('کته ایرانی', $out);
        $this->assertStringNotContains('&Ø', $out);
    }

    public function testZwnjIsPreservedInOutput(): void
    {
        $out = $this->linker()->apply("<p>می\u{200C}پزیم</p>");
        $this->assertStringContains("می\u{200C}پزیم", $out);
    }

    public function testEmptyHtmlIsReturnedUnchanged(): void
    {
        $this->assertSame('', $this->linker()->apply(''));
    }

    public function testHtmlWithNoMatchesIsUnchanged(): void
    {
        $html = '<p>متنی بدون هیچ موضوع شناخته‌شده‌ای.</p>';
        $this->assertStringContains('متنی بدون هیچ موضوع', $this->linker()->apply($html));
    }
}
