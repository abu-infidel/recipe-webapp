/**
 * Turns the structured Persian draft into the HTML the site stores.
 *
 * Citation markers become real links into the References section, and each
 * paragraph gets a stable anchor id so the admin review screen can jump from
 * a claim to the source text supporting it.
 *
 * Output is deliberately plain: the site sanitises it again at publish time
 * against an allow-list, so anything exotic here would simply be stripped.
 */

export function renderHtml(draft) {
  const parts = [];
  const citations = [];

  let sectionIndex = 0;

  for (const section of draft.sections ?? []) {
    sectionIndex++;

    if (section.heading) {
      parts.push(`<h2>${escapeHtml(section.heading)}</h2>`);
    }

    let paragraphIndex = 0;
    for (const paragraph of section.paragraphs ?? []) {
      paragraphIndex++;

      const anchor = `s${sectionIndex}p${paragraphIndex}`;
      const text = String(paragraph.text ?? '').trim();
      if (!text) continue;

      const refs = [...new Set((paragraph.refs ?? []).map(Number))].sort((a, b) => a - b);

      const markers = refs
        .map((ref) => {
          citations.push({ marker: ref, anchor, quote: findQuote(draft, ref), verified: false });
          return `<a class="cite" href="#ref-${ref}">${toPersianDigits(ref)}</a>`;
        })
        .join('');

      parts.push(`<p id="${anchor}">${escapeHtml(text)}${markers}</p>`);
    }
  }

  if (Array.isArray(draft.gaps) && draft.gaps.length > 0) {
    // Surfaced rather than hidden: a reader is better served knowing what the
    // sources did not settle than being given a confident-sounding guess.
    parts.push('<h2>آنچه منابع روشن نکرده‌اند</h2>');
    parts.push('<ul>');
    for (const gap of draft.gaps) {
      parts.push(`<li>${escapeHtml(String(gap))}</li>`);
    }
    parts.push('</ul>');
  }

  return { html: parts.join('\n'), citations };
}

function findQuote(draft, ref) {
  const quote = (draft.key_quotes ?? []).find((q) => Number(q.ref) === ref);
  return quote ? String(quote.quote).slice(0, 2000) : null;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function toPersianDigits(value) {
  return String(value).replace(/\d/g, (d) => String.fromCharCode(0x06F0 + Number(d)));
}
