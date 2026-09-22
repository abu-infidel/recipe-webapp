<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Support\Toc;

final class TocTest extends TestCase
{
    public function run(): void {}

    public function testAddsIdsToHeadings(): void
    {
        $result = Toc::build('<h2>مواد لازم</h2><p>متن</p>');

        $this->assertStringContains('id="مواد-لازم"', $result['html']);
        $this->assertSame(1, count($result['toc']));
        $this->assertSame('مواد لازم', $result['toc'][0]['text']);
    }

    public function testPreservesAnExistingId(): void
    {
        $result = Toc::build('<h2 id="custom">عنوان</h2>');

        $this->assertStringContains('id="custom"', $result['html']);
        $this->assertSame('custom', $result['toc'][0]['id']);
        $this->assertStringNotContains('id="عنوان"', $result['html']);
    }

    public function testDuplicateHeadingsGetDistinctIds(): void
    {
        $result = Toc::build('<h2>نکات</h2><h2>نکات</h2>');

        $this->assertSame('نکات', $result['toc'][0]['id']);
        $this->assertSame('نکات-2', $result['toc'][1]['id']);
    }

    public function testNestsSubheadingsUnderTheirParent(): void
    {
        $result = Toc::build('<h2>الف</h2><h3>الف-۱</h3><h3>الف-۲</h3><h2>ب</h2>');
        $toc = $result['toc'];

        $this->assertSame(2, count($toc), 'two top-level headings');
        $this->assertSame(2, count($toc[0]['children']), 'both h3s nest under the first h2');
        $this->assertSame('ب', $toc[1]['text']);
        $this->assertSame(0, count($toc[1]['children']));
    }

    public function testNestsThreeLevelsDeep(): void
    {
        $result = Toc::build('<h2>الف</h2><h3>ب</h3><h4>ج</h4>');
        $toc = $result['toc'];

        $this->assertSame(1, count($toc));
        $this->assertSame('ب', $toc[0]['children'][0]['text']);
        $this->assertSame('ج', $toc[0]['children'][0]['children'][0]['text']);
    }

    public function testArticleStartingAtH3IsTreatedAsTopLevel(): void
    {
        // The pipeline does not always emit a consistent top level.
        $result = Toc::build('<h3>یک</h3><h4>دو</h4><h3>سه</h3>');
        $toc = $result['toc'];

        $this->assertSame(2, count($toc), 'both h3s are roots');
        $this->assertSame(1, count($toc[0]['children']));
    }

    public function testSkippedLevelAttachesToNearestAncestor(): void
    {
        $result = Toc::build('<h2>الف</h2><h4>عمیق</h4>');
        $toc = $result['toc'];

        $this->assertSame(1, count($toc));
        $this->assertSame('عمیق', $toc[0]['children'][0]['text']);
    }

    public function testReturningRootsAreNotAliased(): void
    {
        // Reference-based assembly can leave the last element bound to a
        // stack variable; mutating one root must not touch another.
        $result = Toc::build('<h2>الف</h2><h2>ب</h2><h2>ج</h2>');
        $toc = $result['toc'];
        $toc[0]['text'] = 'CHANGED';

        $this->assertSame('ب', $toc[1]['text']);
        $this->assertSame('ج', $toc[2]['text']);
    }

    public function testIgnoresH1AndH5(): void
    {
        $result = Toc::build('<h1>عنوان صفحه</h1><h2>بخش</h2><h5>ریز</h5>');
        $this->assertSame(1, count($result['toc']));
    }

    public function testHeadingWithInlineMarkupUsesPlainText(): void
    {
        $result = Toc::build('<h2>روش <em>سنتی</em></h2>');
        $this->assertSame('روش سنتی', $result['toc'][0]['text']);
        $this->assertStringContains('<em>سنتی</em>', $result['html'], 'markup survives in the body');
    }

    public function testEmptyHtmlYieldsEmptyToc(): void
    {
        $this->assertSame([], Toc::build('<p>بدون عنوان</p>')['toc']);
    }
}
