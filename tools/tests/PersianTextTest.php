<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Support\PersianText;

final class PersianTextTest extends TestCase
{
    public function run(): void {}

    // ---- normalize() -----------------------------------------------------

    public function testArabicKafAndYehFoldToPersianForms(): void
    {
        // Typed on an Arabic keyboard vs a Persian one. Same word to a reader.
        $arabic  = "كيك شكلاتي";   // Arabic kaf + Arabic yeh
        $persian = "کیک شکلاتی";   // Persian keheh + Persian yeh

        $this->assertSame(
            PersianText::normalize($persian),
            PersianText::normalize($arabic),
            'Arabic-keyboard spelling must match Persian-keyboard spelling'
        );
    }

    public function testAlefVariantsFoldWhenIndexing(): void
    {
        $this->assertSame(
            PersianText::normalize("اب"),
            PersianText::normalize("آب"),
            'A reader searching without madda must still find "آب"'
        );
        $this->assertSame(
            PersianText::normalize("امادہ"),
            PersianText::normalize("أماده"),
            'Hamza-carrying alef folds to bare alef for indexing'
        );
    }

    public function testTehMarbutaFoldsToHeh(): void
    {
        $this->assertSame(
            PersianText::normalize("مرتبه"),
            PersianText::normalize("مرتبة"),
            'Arabic teh marbuta is written as heh in Persian'
        );
    }

    public function testDiacriticsAreIgnored(): void
    {
        $this->assertSame(
            PersianText::normalize("سلام"),
            PersianText::normalize("سَلامٌ"),
            'Vocalised text must match an unvocalised query'
        );
    }

    public function testTatweelIsStripped(): void
    {
        $this->assertSame(
            PersianText::normalize("کباب"),
            PersianText::normalize("کبــــاب"),
            'Kashida is decoration and must not affect matching'
        );
    }

    public function testDigitsFoldToAscii(): void
    {
        $this->assertSame('250 gram', PersianText::normalize('۲۵۰ gram'));
        $this->assertSame('250 gram', PersianText::normalize('٢٥٠ gram'));
    }

    public function testPersianPunctuationBecomesSeparators(): void
    {
        $this->assertSame(
            'نمک فلفل زردچوبه',
            PersianText::normalize('نمک، فلفل؛ زردچوبه؟')
        );
    }

    public function testGuillemetsAndParenthesesAreSeparators(): void
    {
        $this->assertSame('قورمه سبزی', PersianText::normalize('«قورمه سبزی»'));
    }

    public function testInvisibleCharactersAreRemoved(): void
    {
        $dirty = "قورمه\u{200B} سبزی\u{FEFF}";
        $this->assertSame('قورمه سبزی', PersianText::normalize($dirty));
    }

    public function testNormalizeCollapsesWhitespace(): void
    {
        $this->assertSame('برنج ایرانی', PersianText::normalize("  برنج \n\t ایرانی  "));
    }

    public function testLatinTextIsLowercased(): void
    {
        $this->assertSame('sous vide', PersianText::normalize('Sous VIDE'));
    }

    // ---- ZWNJ handling ---------------------------------------------------

    public function testZwnjBecomesSpaceWhenNormalizing(): void
    {
        $this->assertSame('می رود', PersianText::normalize("می\u{200C}رود"));
    }

    public function testTokenizeEmitsBothJoinedAndSplitFormsOfZwnjWords(): void
    {
        // The single most common Persian search failure: a reader types the
        // word without the half-space and finds nothing.
        $tokens = PersianText::tokenize("کته\u{200C}ای");

        $this->assertContains('کتهای', $tokens, 'joined form must be indexed');
        $this->assertContains('کته', $tokens, 'first part must be indexed');
        $this->assertContains('ای', $tokens, 'second part must be indexed');
    }

    public function testZwnjIsPreservedForDisplay(): void
    {
        $text = "می\u{200C}پزیم";
        $this->assertStringContains(
            PersianText::ZWNJ,
            PersianText::display($text),
            'display() must never destroy the author\'s half-spaces'
        );
    }

    public function testDisplayRemovesSpacesAroundZwnj(): void
    {
        // Pasted text often arrives as "می ‌پزیم", which renders as a wide gap.
        $this->assertSame("می\u{200C}پزیم", PersianText::display("می \u{200C} پزیم"));
    }

    public function testDisplayPreservesAlefForms(): void
    {
        $this->assertSame('آب', PersianText::display('آب'), 'display() must not fold orthography');
    }

    // ---- tokenize() ------------------------------------------------------

    public function testTokenizeDropsVeryShortTokensButKeepsNumbers(): void
    {
        $tokens = PersianText::tokenize('برنج 2 و نمک');

        $this->assertContains('برنج', $tokens);
        $this->assertContains('2', $tokens, 'quantities are meaningful and must survive');
        $this->assertNotContains('و', $tokens, 'single-letter conjunction is noise');
    }

    public function testTokenizeDeduplicates(): void
    {
        $tokens = PersianText::tokenize('نمک نمک نمک');
        $this->assertSame(['نمک'], $tokens);
    }

    public function testTokenizeReturnsEmptyArrayForPunctuationOnly(): void
    {
        $this->assertSame([], PersianText::tokenize('،؛؟!...'));
    }

    // ---- helpers ---------------------------------------------------------

    public function testToPersianDigitsIsInverseOfToAsciiDigits(): void
    {
        $this->assertSame('۱۴۰۳', PersianText::toPersianDigits('1403'));
        $this->assertSame('1403', PersianText::toAsciiDigits('۱۴۰۳'));
    }

    public function testIsRtlDetectsPersianAndRejectsEnglish(): void
    {
        $this->assertTrue(PersianText::isRtl('دستور پخت'));
        $this->assertFalse(PersianText::isRtl('Recipe instructions'));
        $this->assertTrue(PersianText::isRtl('دستور پخت sous vide'), 'mixed but mostly Persian');
    }

    public function testWordCountTreatsZwnjCompoundAsOneWord(): void
    {
        // A reader sees "می‌پزیم" as one word, so reading time should too.
        $this->assertSame(1, PersianText::wordCount("می\u{200C}پزیم"));
        $this->assertSame(3, PersianText::wordCount('برنج و نمک'));
    }

    public function testWordCountIgnoresHtml(): void
    {
        $this->assertSame(2, PersianText::wordCount('<p>برنج <strong>ایرانی</strong></p>'));
    }

    public function testReadingMinutesIsAlwaysAtLeastOne(): void
    {
        $this->assertSame(1, PersianText::readingMinutes('برنج'));
        $this->assertSame(2, PersianText::readingMinutes(str_repeat('کلمه ', 300)));
    }

    // ---- shared fixture --------------------------------------------------

    /**
     * The fixture is also run against public/assets/js/persian.js by
     * tools/tests/run-js.js. Changing behaviour here without changing it there
     * makes the instant-search box suggest articles the server cannot find.
     */
    public function testMatchesSharedFixture(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/persian.json');
        $this->assertTrue($raw !== false, 'fixture must be readable');

        $fixture = json_decode((string) $raw, true);
        $this->assertTrue(is_array($fixture), 'fixture must be valid JSON');

        foreach ($fixture['normalize'] as $case) {
            $this->assertSame($case['out'], PersianText::normalize($case['in']), 'normalize drifted from the fixture');
        }
        foreach ($fixture['tokenize'] as $case) {
            $this->assertSame($case['out'], PersianText::tokenize($case['in']), 'tokenize drifted from the fixture');
        }
    }
}
