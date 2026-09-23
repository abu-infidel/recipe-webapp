<?php
declare(strict_types=1);

namespace App\Domain;

use App\Support\PersianText;

/**
 * Mechanically checks a draft against the sources it was written from.
 *
 * This is what turns "the model was told not to invent references" into
 * something enforced rather than hoped for. It runs on the server as well as
 * in the worker, so a compromised or buggy worker still cannot land a draft
 * citing a source that was never fetched.
 *
 * No model is involved. Every check here is arithmetic or string matching.
 */
final class CitationValidator
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARN  = 'warn';

    /**
     * @param array $draft  {sections: [{heading, paragraphs: [{text, refs: [int]}]}]}
     * @param array<int,array{url:string,extracted_text:string}> $sources keyed by marker
     *
     * @return array{ok:bool, findings:list<array{severity:string,code:string,message:string,anchor:?string}>}
     */
    public static function validate(array $draft, array $sources): array
    {
        $findings = [];
        $sectionIndex = 0;

        foreach ($draft['sections'] ?? [] as $section) {
            $sectionIndex++;
            $heading = (string) ($section['heading'] ?? "section {$sectionIndex}");
            $paragraphIndex = 0;

            foreach ($section['paragraphs'] ?? [] as $paragraph) {
                $paragraphIndex++;
                $anchor = "s{$sectionIndex}p{$paragraphIndex}";
                $text = trim((string) ($paragraph['text'] ?? ''));
                $refs = array_map('intval', (array) ($paragraph['refs'] ?? []));

                if ($text === '') {
                    continue;
                }

                // 1. Every citation must point at a source that was actually
                //    fetched. This is the check that makes an invented
                //    reference impossible rather than unlikely.
                foreach ($refs as $ref) {
                    if (!isset($sources[$ref])) {
                        $findings[] = self::finding(
                            self::SEVERITY_ERROR,
                            'unknown_source',
                            "Paragraph cites [{$ref}], which is not one of the fetched sources.",
                            $anchor
                        );
                    }
                }

                // 2. A factual paragraph with no citation at all.
                if ($refs === [] && self::looksFactual($text)) {
                    $findings[] = self::finding(
                        self::SEVERITY_WARN,
                        'uncited_claim',
                        'Paragraph states specific facts but cites no source.',
                        $anchor
                    );
                }

                // 3. Every number in the paragraph must appear in at least one
                //    of the sources that paragraph itself cites. This is the
                //    check that catches a plausible-sounding but invented
                //    temperature, weight or time.
                //
                //    A hand-entered reference may have no stored text at all
                //    (the site never fetches pages itself). Then the figures
                //    cannot be checked either way, and one finding saying so
                //    is more useful than one per number.
                $unverifiable = $refs !== [] && self::noneHaveText($refs, $sources);
                $unchecked = [];

                foreach (self::numbersIn($text) as $number) {
                    if (self::isCommonNumber($number)) {
                        continue;
                    }

                    if ($unverifiable) {
                        $unchecked[] = $number;
                        continue;
                    }

                    $supported = false;
                    foreach ($refs as $ref) {
                        if (isset($sources[$ref]) && self::sourceContainsNumber($sources[$ref], $number)) {
                            $supported = true;
                            break;
                        }
                    }

                    if (!$supported) {
                        $findings[] = self::finding(
                            self::SEVERITY_WARN,
                            'unsupported_number',
                            "The value \"{$number}\" does not appear in any source this paragraph cites.",
                            $anchor
                        );
                    }
                }

                if ($unchecked !== []) {
                    $findings[] = self::finding(
                        self::SEVERITY_WARN,
                        'unverifiable_number',
                        'The figures ' . implode(', ', $unchecked) . ' cannot be checked: the sources this paragraph cites have no stored text. Add a supporting quote to the reference.',
                        $anchor
                    );
                }
            }
        }

        // 4. A source that was fetched but never cited is usually a sign the
        //    draft drifted from its research.
        $cited = self::citedMarkers($draft);
        foreach (array_keys($sources) as $marker) {
            if (!in_array($marker, $cited, true)) {
                $findings[] = self::finding(
                    self::SEVERITY_WARN,
                    'unused_source',
                    "Source [{$marker}] was fetched but never cited.",
                    null
                );
            }
        }

        $hasError = false;
        foreach ($findings as $finding) {
            if ($finding['severity'] === self::SEVERITY_ERROR) {
                $hasError = true;
                break;
            }
        }

        return ['ok' => !$hasError, 'findings' => $findings];
    }

    /** True when every cited source is known and none has any text to check. */
    private static function noneHaveText(array $refs, array $sources): bool
    {
        foreach ($refs as $ref) {
            if (!isset($sources[$ref]) || trim((string) ($sources[$ref]['extracted_text'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private static function citedMarkers(array $draft): array
    {
        $markers = [];

        foreach ($draft['sections'] ?? [] as $section) {
            foreach ($section['paragraphs'] ?? [] as $paragraph) {
                foreach ((array) ($paragraph['refs'] ?? []) as $ref) {
                    $markers[(int) $ref] = true;
                }
            }
        }

        return array_keys($markers);
    }

    /**
     * Numbers written in either Persian or ASCII digits, with the decimal
     * separator normalised.
     *
     * @return list<string>
     */
    public static function numbersIn(string $text): array
    {
        $ascii = str_replace('٫', '.', PersianText::toAsciiDigits($text));

        if (preg_match_all('/\d+(?:\.\d+)?/', $ascii, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Whether a source mentions a value.
     *
     * Matched as a whole number, so "35" is not found inside "135" or "2035",
     * and with tolerance for a converted temperature, because a source may
     * say 350F where the draft says 177C. The conversion is only tried for
     * values that can be cooking temperatures: applied to small numbers it
     * turned 35 into "1" or "2", which nearly every source contains.
     */
    private static function sourceContainsNumber(array $source, string $number): bool
    {
        $haystack = str_replace([',', '٫'], ['', '.'], PersianText::toAsciiDigits((string) ($source['extracted_text'] ?? '')));

        if (self::containsWholeNumber($haystack, $number)) {
            return true;
        }

        $value = (float) $number;
        if ($value >= 30) {
            foreach ([$value * 9 / 5 + 32, ($value - 32) * 5 / 9] as $converted) {
                if ($converted < 30) {
                    continue;
                }
                foreach ([floor($converted), ceil($converted), round($converted)] as $candidate) {
                    if (self::containsWholeNumber($haystack, (string) (int) $candidate)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function containsWholeNumber(string $haystack, string $number): bool
    {
        return preg_match('/(?<![\d.])' . preg_quote($number, '/') . '(?!\d|\.\d)/', $haystack) === 1;
    }

    /**
     * Small integers are ordinals, step counts and list positions rather than
     * factual claims, and flagging them would bury the real findings.
     */
    private static function isCommonNumber(string $number): bool
    {
        if (!ctype_digit($number)) {
            return false;
        }

        $value = (int) $number;

        return $value <= 12 || ($value >= 1300 && $value <= 1500);   // small counts, Jalali years
    }

    /**
     * A rough test for "this paragraph asserts something checkable" — it
     * contains a number or a unit. Intentionally conservative: this produces
     * a warning for a person to judge, never a rejection.
     */
    private static function looksFactual(string $text): bool
    {
        if (preg_match('/\d/u', PersianText::toAsciiDigits($text)) === 1) {
            return true;
        }

        foreach (['درجه', 'گرم', 'کیلو', 'لیتر', 'دقیقه', 'ساعت', 'درصد', 'کالری'] as $unit) {
            if (str_contains($text, $unit)) {
                return true;
            }
        }

        return false;
    }

    private static function finding(string $severity, string $code, string $message, ?string $anchor): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message, 'anchor' => $anchor];
    }
}
