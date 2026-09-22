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

      for (const number of numbersIn(text)) {
        if (isCommonNumber(number)) continue;

        const supported = refs.some((ref) => {
          const source = byMarker.get(ref);
          return source && sourceContainsNumber(source, number);
        });

        if (!supported) {
          findings.push(finding('warn', 'unsupported_number',
            `The value "${number}" does not appear in any source this paragraph cites.`, anchor));
        }
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

  if (haystack.includes(number)) return true;

  // A source may say 145F where the draft sensibly writes 63C.
  const value = Number(number);
  if (value > 0) {
    for (const converted of [value * 9 / 5 + 32, (value - 32) * 5 / 9]) {
      for (const candidate of [Math.floor(converted), Math.ceil(converted), Math.round(converted)]) {
        if (candidate > 0 && haystack.includes(String(candidate))) return true;
      }
    }
  }

  return false;
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
