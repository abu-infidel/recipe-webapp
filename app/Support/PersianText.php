<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Persian/Farsi text handling.
 *
 * This class is the single source of truth for how Persian text is compared,
 * indexed and searched. `public/assets/js/persian.js` mirrors it for the
 * client-side instant-search box; `tools/tests/PersianTextTest.php` runs the
 * shared fixture in `tools/tests/fixtures/persian.json` against both so they
 * cannot drift apart.
 *
 * The distinction that matters throughout:
 *
 *   display()   - light cleanup, safe to show to a reader. Preserves ZWNJ,
 *                 preserves the author's choice of alef/hamza forms.
 *   normalize() - aggressive folding for indexing and matching only. Never
 *                 store its output as content; it destroys real orthography.
 */
final class PersianText
{
    /** Zero-width non-joiner (نیم‌فاصله) — meaningful in Persian, never blindly stripped. */
    public const ZWNJ = "\u{200C}";

    /**
     * Characters that carry no meaning but routinely arrive pasted in from
     * Word, PDFs and websites. Removed in both display() and normalize().
     * ZWNJ is deliberately absent — it is handled separately.
     */
    private const INVISIBLES = [
        "\u{200B}", // zero-width space
        "\u{200D}", // zero-width joiner
        "\u{200E}", // LTR mark
        "\u{200F}", // RTL mark
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", // bidi embedding
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",             // bidi isolates
        "\u{FEFF}", // BOM
        "\u{0640}", // tatweel / kashida — pure decoration
    ];

    /**
     * Arabic-script characters that Persian keyboards and copy-paste produce
     * interchangeably. Folding them is required for search to work at all:
     * a reader typing Arabic yeh must find text stored with Persian yeh.
     */
    private const CHAR_FOLD = [
        // Arabic kaf -> Persian keheh
        "\u{0643}" => "\u{06A9}",
        "\u{06AA}" => "\u{06A9}",
        // Arabic yeh / alef maksura / hamza-yeh -> Persian yeh
        "\u{064A}" => "\u{06CC}",
        "\u{0649}" => "\u{06CC}",
        "\u{06D2}" => "\u{06CC}",
        // Heh variants -> Persian heh
        "\u{06C0}" => "\u{0647}",
        "\u{06C1}" => "\u{0647}",
        "\u{06D5}" => "\u{0647}",
        // Alternative alef/waw presentation forms
        "\u{0675}" => "\u{0627}",
        "\u{0676}" => "\u{0648}",
        "\u{0677}" => "\u{0648}",
        "\u{0678}" => "\u{06CC}",
    ];

    /**
     * Folded only when indexing. A reader searching "اب" should find "آب",
     * but we must never rewrite the author's "آب" to "اب" on the page.
     */
    private const INDEX_FOLD = [
        "\u{0622}" => "\u{0627}", // alef with madda      آ -> ا
        "\u{0623}" => "\u{0627}", // alef with hamza above أ -> ا
        "\u{0625}" => "\u{0627}", // alef with hamza below إ -> ا
        "\u{0671}" => "\u{0627}", // alef wasla            ٱ -> ا
        "\u{0629}" => "\u{0647}", // teh marbuta           ة -> ه
        "\u{0624}" => "\u{0648}", // waw with hamza        ؤ -> و
        "\u{0626}" => "\u{06CC}", // yeh with hamza        ئ -> ی
        "\u{0621}" => "",         // standalone hamza      ء -> (dropped)
    ];

    private const PERSIAN_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    private const ARABIC_DIGITS  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    private const ASCII_DIGITS   = ['0','1','2','3','4','5','6','7','8','9'];

    /**
     * Harakat (tashkeel). Persian text rarely uses them, but imported and
     * AI-generated text often does, and an unvocalised query must still match
     * vocalised text.
     */
    private const DIACRITICS = '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u';

    /**
     * Cleanup that is safe to apply to text a reader will see.
     *
     * Removes invisible junk and normalises whitespace, but leaves ZWNJ,
     * alef forms, hamza and digit style exactly as the author wrote them.
     */
    public static function display(string $text): string
    {
        $text = str_replace(self::INVISIBLES, '', $text);
        $text = strtr($text, self::CHAR_FOLD);

        // Collapse runs of spaces/tabs without touching line structure.
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? $text;
        // Never leave a ZWNJ orphaned next to a space — it renders as a gap.
        $text = preg_replace('/ *' . self::ZWNJ . ' */u', self::ZWNJ, $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Aggressive folding for indexing, alias matching and de-duplication.
     *
     * Output is lowercase, ASCII-digit, diacritic-free, with ZWNJ turned into
     * a space and all punctuation reduced to single spaces. Two strings a
     * Persian reader would consider "the same words" produce the same output.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(self::INVISIBLES, '', $text);
        $text = strtr($text, self::CHAR_FOLD);
        $text = strtr($text, self::INDEX_FOLD);
        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;
        $text = self::toAsciiDigits($text);

        // ZWNJ becomes a word boundary. tokenize() additionally emits the
        // joined form, so both "می‌رود" and "میرود" find each other.
        $text = str_replace(self::ZWNJ, ' ', $text);

        $text = mb_strtolower($text, 'UTF-8');

        // Anything that is not a letter, mark, digit or ASCII word char is a
        // separator. This covers Persian punctuation (، ؛ ؟ « ») for free.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Split text into index tokens.
     *
     * Words written with ZWNJ are emitted twice: once split on the ZWNJ and
     * once joined. "کته‌ای" yields "کته", "ای" and "کتهای", so a reader finds
     * the article whichever way they type it. Tokens shorter than $minLength
     * are dropped unless they are digits, which are usually quantities.
     *
     * @return list<string>
     */
    public static function tokenize(string $text, int $minLength = 2): array
    {
        $text = str_replace(self::INVISIBLES, '', $text);
        $text = strtr($text, self::CHAR_FOLD);
        $text = strtr($text, self::INDEX_FOLD);
        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;
        $text = self::toAsciiDigits($text);
        $text = mb_strtolower($text, 'UTF-8');

        // Keep ZWNJ through the split so we can see which words contained one.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}_' . self::ZWNJ . ']+/u', ' ', $text) ?? $text;

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!str_contains($word, self::ZWNJ)) {
                $tokens[] = $word;
                continue;
            }
            $tokens[] = str_replace(self::ZWNJ, '', $word);            // joined form
            foreach (explode(self::ZWNJ, $word) as $part) {            // split parts
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        // Deduplicate while preserving string type: PHP array keys silently
        // cast "2" to int 2, which would corrupt quantity tokens.
        $seen = [];
        $keep = [];
        foreach ($tokens as $token) {
            if (isset($seen[$token])) {
                continue;
            }
            if (mb_strlen($token, 'UTF-8') >= $minLength || ctype_digit($token)) {
                $seen[$token] = true;
                $keep[] = $token;
            }
        }

        return $keep;
    }

    /**
     * normalize(), plus a map from each character of the result back to its
     * index in the input.
     *
     * Needed by the auto-linker, which has to match on normalised text (a
     * reader may write "کته‌ای" where the alias is "کته ای") but must splice
     * the link onto the original characters.
     *
     * Normalising character by character and concatenating does not work:
     * normalize() trims, so every space would vanish and no multi-word phrase
     * could ever match. The folding is therefore replayed here with separator
     * handling made explicit. testNormalizeWithMapMatchesNormalize keeps the
     * two in agreement.
     *
     * @return array{0:string, 1:list<int>} normalised text, and map[i] = source index
     */
    public static function normalizeWithMap(string $text): array
    {
        $out = '';
        $map = [];
        $pendingSpace = false;
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            if (in_array($char, self::INVISIBLES, true)) {
                continue;
            }

            $char = strtr($char, self::CHAR_FOLD);
            $char = strtr($char, self::INDEX_FOLD);

            if ($char === '') {
                continue;   // folded away entirely, e.g. a standalone hamza
            }
            if (preg_match(self::DIACRITICS, $char) === 1) {
                continue;
            }

            if ($char === self::ZWNJ) {
                $pendingSpace = true;
                continue;
            }

            $char = mb_strtolower(self::toAsciiDigits($char), 'UTF-8');

            if (preg_match('/[^\p{L}\p{M}\p{N}_]/u', $char) === 1) {
                $pendingSpace = true;
                continue;
            }

            // A separator only becomes a space when real text follows it, so
            // leading and trailing runs disappear exactly as trim() would.
            if ($pendingSpace && $out !== '') {
                $out .= ' ';
                $map[] = $i;
            }
            $pendingSpace = false;

            $charLength = mb_strlen($char, 'UTF-8');
            for ($j = 0; $j < $charLength; $j++) {
                $map[] = $i;
            }
            $out .= $char;
        }

        return [$out, $map];
    }

    public static function toAsciiDigits(string $text): string
    {
        return str_replace(
            [...self::PERSIAN_DIGITS, ...self::ARABIC_DIGITS],
            [...self::ASCII_DIGITS, ...self::ASCII_DIGITS],
            $text
        );
    }

    /** For display only — Persian readers expect Persian numerals in prose. */
    public static function toPersianDigits(string $text): string
    {
        return str_replace(self::ASCII_DIGITS, self::PERSIAN_DIGITS, $text);
    }

    /** True when the text is predominantly Persian/Arabic script. */
    public static function isRtl(string $text): bool
    {
        $rtl = preg_match_all('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
        $ltr = preg_match_all('/[a-zA-Z]/', $text);

        return $rtl > 0 && $rtl >= $ltr;
    }

    /**
     * Word count for reading-time estimates. Persian has no reliable
     * whitespace-to-word mapping because of ZWNJ compounds, so a ZWNJ
     * compound counts as the one word a reader perceives.
     */
    public static function wordCount(string $text): int
    {
        $text = strip_tags($text);
        $text = str_replace(self::ZWNJ, '', $text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    /**
     * Reading time in minutes. 200 wpm is the usual English figure; Persian
     * prose reads slightly slower, so we use 180 and always return >= 1.
     */
    public static function readingMinutes(string $text, int $wordsPerMinute = 180): int
    {
        return max(1, (int) ceil(self::wordCount($text) / $wordsPerMinute));
    }
}
