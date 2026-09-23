<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Support\HtmlSanitizer;

final class HtmlSanitizerTest extends TestCase
{
    public function run(): void {}

    public function testKeepsOrdinaryProse(): void
    {
        $out = HtmlSanitizer::clean('<p>متن <strong>پررنگ</strong> و <em>مورب</em></p>');

        $this->assertStringContains('<strong>پررنگ</strong>', $out);
        $this->assertStringContains('<em>مورب</em>', $out);
    }

    public function testRemovesScriptEntirely(): void
    {
        $out = HtmlSanitizer::clean('<p>سلام</p><script>alert(1)</script>');

        $this->assertStringNotContains('script', $out);
        $this->assertStringNotContains('alert', $out, 'script text is not content and must go too');
    }

    public function testRemovesEventHandlers(): void
    {
        $out = HtmlSanitizer::clean('<p onclick="steal()">متن</p>');

        $this->assertStringNotContains('onclick', $out);
        $this->assertStringContains('متن', $out);
    }

    public function testStripsJavascriptUrls(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">کلیک</a>');

        $this->assertStringNotContains('javascript:', $out);
        $this->assertStringContains('کلیک', $out, 'the text survives, only the URL goes');
    }

    public function testStripsDataUrls(): void
    {
        $out = HtmlSanitizer::clean('<img src="data:text/html;base64,PHNjcmlwdD4=" alt="x">');
        $this->assertStringNotContains('data:', $out);
    }

    public function testUnwrapsUnknownTagsKeepingText(): void
    {
        $out = HtmlSanitizer::clean('<p>یک <font color="red">کلمه</font> رنگی</p>');

        $this->assertStringNotContains('<font', $out);
        $this->assertStringContains('کلمه', $out);
    }

    public function testRemovesIframes(): void
    {
        $out = HtmlSanitizer::clean('<iframe src="https://evil.test"></iframe><p>متن</p>');

        $this->assertStringNotContains('iframe', $out);
        $this->assertStringContains('متن', $out);
    }

    public function testAddsRelProtectionToExternalLinks(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://example.com">پیوند</a>');

        $this->assertStringContains('rel="nofollow noopener"', $out);
        $this->assertStringContains('target="_blank"', $out);
    }

    public function testLeavesInternalLinksAlone(): void
    {
        $out = HtmlSanitizer::clean('<a href="/ashpazi/khoresh">خورش</a>');

        $this->assertStringNotContains('target="_blank"', $out);
        $this->assertStringContains('href="/ashpazi/khoresh"', $out);
    }

    public function testKeepsCitationMarkers(): void
    {
        // The pipeline emits these; they must survive sanitising intact.
        $out = HtmlSanitizer::clean('<p>ادعا <a class="cite" href="#ref-1">۱</a></p>');

        $this->assertStringContains('class="cite"', $out);
        $this->assertStringContains('href="#ref-1"', $out);
    }

    public function testStripsUnknownClasses(): void
    {
        $out = HtmlSanitizer::clean('<span class="evil-tracker">متن</span>');
        $this->assertStringNotContains('evil-tracker', $out);
    }

    public function testKeepsHeadingIds(): void
    {
        $out = HtmlSanitizer::clean('<h2 id="مواد-لازم">مواد لازم</h2>');
        $this->assertStringContains('id="مواد-لازم"', $out);
    }

    public function testKeepsTables(): void
    {
        $out = HtmlSanitizer::clean('<table><tr><th>الف</th><td>ب</td></tr></table>');

        $this->assertStringContains('<table>', $out);
        $this->assertStringContains('<th>الف</th>', $out);
    }

    public function testRemovesComments(): void
    {
        $out = HtmlSanitizer::clean('<p>متن</p><!-- hidden note -->');
        $this->assertStringNotContains('hidden note', $out);
    }

    public function testPersianIsNotMangled(): void
    {
        $out = HtmlSanitizer::clean("<p>نان\u{200C}های سنتی ایرانی</p>");

        $this->assertStringContains("نان\u{200C}های سنتی ایرانی", $out);
        $this->assertStringNotContains('Ø', $out, 'libxml must be told the input is UTF-8');
    }

    public function testEmptyInputReturnsEmptyString(): void
    {
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function testRemovesForms(): void
    {
        $out = HtmlSanitizer::clean('<form action="https://evil.test"><input name="pw"></form><p>متن</p>');

        $this->assertStringNotContains('<form', $out);
        $this->assertStringNotContains('<input', $out);
    }

    public function testKeepsLocalMediaImages(): void
    {
        $out = HtmlSanitizer::clean('<img src="/media/2026/ab12cd.webp" alt="قورمه">');
        $this->assertStringContains('src="/media/2026/ab12cd.webp"', $out);
    }

    public function testRemovesRemoteImagesEntirely(): void
    {
        // A remote image is a tracking pixel and a blackout-breaking request.
        $out = HtmlSanitizer::clean('<p>متن</p><img src="https://tracker.example/p.gif">');

        $this->assertStringNotContains('<img', $out, 'an image with no allowed source is dropped, not left empty');
        $this->assertStringContains('متن', $out);
    }

    public function testRemovesProtocolRelativeImages(): void
    {
        $out = HtmlSanitizer::clean('<img src="//tracker.example/p.gif">');
        $this->assertStringNotContains('<img', $out);
    }

    public function testRejectsTraversalInMediaPath(): void
    {
        $out = HtmlSanitizer::clean('<img src="/media/../../app/config.local.php">');
        $this->assertStringNotContains('<img', $out);
    }

    public function testScriptInsideAWorkerDraftIsNeutralised(): void
    {
        // The shape a compromised worker would send to land stored XSS in the
        // admin review screen.
        $payload = '<p>متن عادی</p><img src=x onerror="fetch(\'/admin\')"><svg onload=alert(1)><a href="javascript:alert(1)">x</a>';
        $out = HtmlSanitizer::clean($payload);

        $this->assertStringNotContains('onerror', $out);
        $this->assertStringNotContains('onload', $out);
        $this->assertStringNotContains('<svg', $out);
        $this->assertStringNotContains('javascript:', $out);
        $this->assertStringContains('متن عادی', $out);
    }
}
