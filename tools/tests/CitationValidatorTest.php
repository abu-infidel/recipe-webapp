<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Domain\CitationValidator;

final class CitationValidatorTest extends TestCase
{
    public function run(): void {}

    private function sources(): array
    {
        return [
            1 => ['url' => 'https://a.test', 'extracted_text' => 'Hold the meat between 85C and 95C for two to three hours.'],
            2 => ['url' => 'https://b.test', 'extracted_text' => 'Beef roasts: 145F with a three-minute rest.'],
        ];
    }

    private function draft(array $paragraphs): array
    {
        return ['sections' => [['heading' => 'بخش', 'paragraphs' => $paragraphs]]];
    }

    private function codes(array $result): array
    {
        return array_column($result['findings'], 'code');
    }

    public function testAcceptsAWellCitedDraft(): void
    {
        $result = CitationValidator::validate(
            $this->draft([
                ['text' => 'گوشت را بین ۸۵ تا ۹۵ درجه نگه دارید.', 'refs' => [1]],
                ['text' => 'دمای ایمن ۱۴۵ درجه فارنهایت است.', 'refs' => [2]],
            ]),
            $this->sources()
        );

        $this->assertTrue($result['ok'], 'a correctly cited draft must pass');
        $this->assertNotContains('unsupported_number', $this->codes($result));
    }

    public function testRejectsACitationToASourceThatWasNeverFetched(): void
    {
        // The core guarantee: a reference the pipeline never fetched cannot
        // reach a published page.
        $result = CitationValidator::validate(
            $this->draft([['text' => 'ادعایی با منبع جعلی.', 'refs' => [7]]]),
            $this->sources()
        );

        $this->assertFalse($result['ok'], 'an invented reference must fail the draft');
        $this->assertContains('unknown_source', $this->codes($result));
    }

    public function testFlagsANumberThatNoCitedSourceSupports(): void
    {
        $result = CitationValidator::validate(
            $this->draft([['text' => 'گوشت را تا ۲۵۰ درجه حرارت دهید.', 'refs' => [1]]]),
            $this->sources()
        );

        $this->assertContains('unsupported_number', $this->codes($result));
    }

    public function testAcceptsANumberFoundInACitedSource(): void
    {
        $result = CitationValidator::validate(
            $this->draft([['text' => 'دمای ۹۵ درجه مناسب است.', 'refs' => [1]]]),
            $this->sources()
        );

        $this->assertNotContains('unsupported_number', $this->codes($result));
    }

    public function testAcceptsAConvertedTemperature(): void
    {
        // The source says 145F; the Persian draft sensibly writes 63C.
        $result = CitationValidator::validate(
            $this->draft([['text' => 'دمای مرکزی ۶۳ درجه سانتی‌گراد.', 'refs' => [2]]]),
            $this->sources()
        );

        $this->assertNotContains('unsupported_number', $this->codes($result));
    }

    public function testDoesNotCheckNumbersAgainstUncitedSources(): void
    {
        // 145 is in source 2, but this paragraph only cites source 1.
        $result = CitationValidator::validate(
            $this->draft([['text' => 'دمای ۱۴۵ درجه.', 'refs' => [1]]]),
            $this->sources()
        );

        $this->assertContains('unsupported_number', $this->codes($result));
    }

    public function testWarnsOnAFactualParagraphWithNoCitation(): void
    {
        $result = CitationValidator::validate(
            $this->draft([['text' => 'این غذا ۵۰۰ کالری دارد.', 'refs' => []]]),
            $this->sources()
        );

        $this->assertContains('uncited_claim', $this->codes($result));
    }

    public function testDoesNotWarnOnProseWithNoFactualClaim(): void
    {
        $result = CitationValidator::validate(
            $this->draft([['text' => 'این غذا در ایران بسیار محبوب است.', 'refs' => []]]),
            $this->sources()
        );

        $this->assertNotContains('uncited_claim', $this->codes($result));
    }

    public function testIgnoresSmallOrdinals(): void
    {
        // Step numbers and small counts are not factual claims.
        $result = CitationValidator::validate(
            $this->draft([['text' => 'در ۳ مرحله انجام می‌شود.', 'refs' => [1]]]),
            $this->sources()
        );

        $this->assertNotContains('unsupported_number', $this->codes($result));
    }

    public function testReportsASourceThatWasNeverCited(): void
    {
        $result = CitationValidator::validate(
            $this->draft([['text' => 'متن با ارجاع.', 'refs' => [1]]]),
            $this->sources()
        );

        $this->assertContains('unused_source', $this->codes($result));
    }

    public function testHandlesPersianDigits(): void
    {
        $this->assertContains('95', CitationValidator::numbersIn('دمای ۹۵ درجه'));
        $this->assertContains('2.5', CitationValidator::numbersIn('۲٫۵ پیمانه'));
    }

    public function testAnEmptyDraftIsNotAnError(): void
    {
        $result = CitationValidator::validate(['sections' => []], []);
        $this->assertTrue($result['ok']);
    }

    /** The shared fixture that worker/src/pipeline/validate.js is also run against. */
    public function testSharedFixtureWithTheWorker(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/citations.json'), true);

        foreach ($fixture['cases'] as $case) {
            $sources = [];
            foreach ($case['sources'] as $i => $source) {
                $sources[$i + 1] = $source;
            }
            $findings = CitationValidator::validate($case['draft'], $sources)['findings'];
            $this->assertSame(
                $case['expect'],
                array_map(static fn(array $f) => $f['code'] . '@' . ($f['anchor'] ?? 'null'), $findings),
                $case['name']
            );
        }
    }
}
