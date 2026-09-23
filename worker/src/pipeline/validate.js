/**
 * The worker's copy of the citation check.
 *
 * Mirrors app/Domain/CitationValidator.php. It runs here so that a repair
 * round can happen before anything is sent, but the server's copy is the one
 * that decides — a check the worker could skip would not be a guarantee.
 */

/**
 * @returns {Array<{severity: string, code: string, message: string, anchor: string|null}>}
 */
export function validateDraft(draft, sources) {
  const findings = [];
  const byMarker = new Map(sources.map((source, index) => [index + 1, source]));

  let sectionIndex = 0;
  const cited = new Set();

  for (const section of draft.sections ?? []) {
    sectionIndex++;
    let paragraphIndex = 0;

    for (const paragraph of section.paragraphs ?? []) {
      paragraphIndex++;
      const anchor = `s${sectionIndex}p${paragraphIndex}`;
      const text = String(paragraph.text ?? '').trim();
      const refs = (paragraph.refs ?? []).map(Number);

      if (!text) continue;

      for (const ref of refs) {
        cited.add(ref);

        // The core guarantee: a citation to something never fetched.
        if (!byMarker.has(ref)) {
          findings.push(finding('error', 'unknown_source',
            `Paragraph cites [${ref}], which is not one of the fetched sources.`, anchor));
        }
      }

      if (refs.length === 0 && looksFactual(text)) {
        findings.push(finding('warn', 'uncited_claim',
          'Paragraph states specific facts but cites no source.', anchor));
      }

      // A reference with no stored text cannot support or refute a figure;
      // say so once per paragraph. Mirrors CitationValidator.php.
      const unverifiable = refs.length > 0 && refs.every((ref) => {
        const source = byMarker.get(ref);
        return source && String(source.extracted_text ?? '').trim() === '';
      });
      const unchecked = [];

      for (const number of numbersIn(text)) {
        if (isCommonNumber(number)) continue;

        if (unverifiable) {
          unchecked.push(number);
          continue;
        }

        const supported = refs.some((ref) => {
          const source = byMarker.get(ref);
          return source && sourceContainsNumber(source, number);
        });

        if (!supported) {
          findings.push(finding('warn', 'unsupported_number',
            `The value "${number}" does not appear in any source this paragraph cites.`, anchor));
        }
      }

      if (unchecked.length > 0) {
        findings.push(finding('warn', 'unverifiable_number',
          `The figures ${unchecked.join(', ')} cannot be checked: the sources this paragraph cites have no stored text. Add a supporting quote to the reference.`, anchor));
      }
    }
  }

  for (const marker of byMarker.keys()) {
    if (!cited.has(marker)) {
      findings.push(finding('warn', 'unused_source',
        `Source [${marker}] was fetched but never cited.`, null));
    }
  }

  return findings;
}

export function numbersIn(text) {
  const ascii = toAsciiDigits(String(text)).replace(/٫/g, '.');
  return [...new Set(ascii.match(/\d+(?:\.\d+)?/g) ?? [])];
}

function sourceContainsNumber(source, number) {
  const haystack = toAsciiDigits(String(source.extracted_text ?? source.text ?? ''))
    .replace(/,/g, '')
    .replace(/٫/g, '.');

  if (containsWholeNumber(haystack, number)) return true;

  // A source may say 350F where the draft sensibly writes 177C. Only for
  // values that can be cooking temperatures — on small numbers the
  // conversion produced "1" or "2", which nearly every source contains.
  // Mirrors CitationValidator.php.
  const value = Number(number);
  if (value >= 30) {
    for (const converted of [value * 9 / 5 + 32, (value - 32) * 5 / 9]) {
      if (converted < 30) continue;
      for (const candidate of [Math.floor(converted), Math.ceil(converted), Math.round(converted)]) {
        if (containsWholeNumber(haystack, String(candidate))) return true;
      }
    }
  }

  return false;
}

/** "35" must not be found inside "135" or "2035". */
function containsWholeNumber(haystack, number) {
  const escaped = number.replace(/\./g, '\\.');
  return new RegExp(`(?<![\\d.])${escaped}(?!\\d|\\.\\d)`).test(haystack);
}

/** Small integers are step numbers and counts, not factual claims. */
function isCommonNumber(number) {
  if (!/^\d+$/.test(number)) return false;
  const value = Number(number);
  return value <= 12 || (value >= 1300 && value <= 1500);
}

function looksFactual(text) {
  if (/\d/.test(toAsciiDigits(text))) return true;
  return ['degree', 'gram', 'minute', 'hour', 'percent', 'calorie',
          'درجه', 'گرم', 'دقیقه', 'ساعت', 'درصد', 'کالری'].some((unit) => text.includes(unit));
}

function toAsciiDigits(text) {
  return text
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));
}

function finding(severity, code, message, anchor) {
  return { severity, code, message, anchor };
}
