/**
 * The prompts.
 *
 * The rule that governs all of them: the model may only assert what the
 * numbered sources in front of it say. It is not asked to be honest about
 * this and then trusted — every claim is checked mechanically afterwards, and
 * a citation to a source that was not fetched is rejected outright. The
 * prompts exist to make the model's output easy to check, not to make the
 * checking unnecessary.
 */

export function planPrompt(topic, kind) {
  const shape = kind === 'recipe'
    ? 'a recipe page: what it is, ingredients, method, common failure modes, storage'
    : 'a practical how-to guide: what the task is, what is needed, the steps, what goes wrong';

  return [
    {
      role: 'system',
      content: [
        'You plan research for a Persian-language reference site.',
        'You do not write the article. You decide what must be found out first.',
        '',
        'Return JSON only, with this exact shape:',
        '{',
        '  "title_en": "short working title in English",',
        '  "outline": [{"heading": "...", "purpose": "what this section must establish"}],',
        '  "questions": ["specific, searchable factual questions"],',
        '  "risk_notes": ["anything about this topic where being wrong could hurt someone"]',
        '}',
        '',
        'Rules:',
        '- 4 to 7 outline sections.',
        '- 5 to 9 questions. Each must be answerable from a document, not a matter of taste.',
        '- Ask about quantities, temperatures, times and safety thresholds explicitly.',
        '- If the topic touches food safety, medicine or anything that could injure',
        '  someone, say so in risk_notes.',
      ].join('\n'),
    },
    {
      role: 'user',
      content: `Topic: ${topic}\nThis will become ${shape}.\nPlan the research.`,
    },
  ];
}

/**
 * The synthesis step.
 *
 * The model sees only the numbered sources. It is told plainly that its own
 * knowledge is not admissible, and every paragraph must carry the markers of
 * the sources supporting it.
 */
export function synthesisPrompt(topic, outline, sources, riskNotes = []) {
  const numbered = sources
    .map((source, index) => [
      `### [${index + 1}] ${source.title || source.url}`,
      `URL: ${source.url}`,
      source.publishedDate ? `Published: ${source.publishedDate}` : '',
      '',
      source.text.slice(0, 12000),
    ].filter(Boolean).join('\n'))
    .join('\n\n---\n\n');

  return [
    {
      role: 'system',
      content: [
        'You write reference articles strictly from provided sources.',
        '',
        'ABSOLUTE RULES',
        '1. You may only state what the numbered sources below say. Your own',
        '   knowledge of this topic is NOT admissible, however confident you are.',
        '2. Every paragraph must list the source numbers supporting it in "refs".',
        '3. Never cite a number that is not in the list you were given. There is',
        '   an automatic check; a made-up citation fails the whole draft.',
        '4. Every quantity, temperature, time and measurement you write must',
        '   appear in a source you cite for that same paragraph.',
        '5. If the sources do not answer part of the outline, write a paragraph',
        '   in "gaps" saying so. Do not fill the hole from memory.',
        '6. If sources disagree, say both and cite both. Do not silently pick one.',
        '',
        'Write in clear English. It will be rendered into Persian afterwards by a',
        'separate step; reasoning and citation accuracy are measurably better in',
        'English, which is why this step is not Persian.',
        '',
        'Return JSON only:',
        '{',
        '  "title_en": "...",',
        '  "summary": "2-3 sentences, plain, no citations needed",',
        '  "sections": [',
        '    {"heading": "...", "paragraphs": [{"text": "...", "refs": [1, 3]}]}',
        '  ],',
        '  "key_quotes": [{"ref": 1, "quote": "verbatim sentence from that source"}],',
        '  "gaps": ["what the sources did not establish"],',
        '  "recipe": null,',
        '  "confidence": "high" | "medium" | "low"',
        '}',
        '',
        'For a recipe, set "recipe" to:',
        '{"yield_number": 4, "yield_unit": "نفر", "prep_minutes": 0, "cook_minutes": 0,',
        ' "difficulty": "easy|medium|hard",',
        ' "ingredients": [{"quantity": 1, "unit": "...", "name": "...", "note": ""}],',
        ' "steps": [{"text": "..."}]}',
        'Ingredient and step text stays English here; it is translated later.',
      ].join('\n'),
    },
    {
      role: 'user',
      content: [
        `Topic: ${topic}`,
        '',
        'Outline to follow:',
        outline.map((section, i) => `${i + 1}. ${section.heading} — ${section.purpose}`).join('\n'),
        riskNotes.length ? `\nSafety-critical aspects: ${riskNotes.join('; ')}` : '',
        '',
        `SOURCES (you may cite [1] to [${sources.length}] and nothing else):`,
        '',
        numbered,
      ].filter(Boolean).join('\n'),
    },
  ];
}

/**
 * The Persian rendering step.
 *
 * Kept separate from synthesis so that citation markers can be checked before
 * and after: if a marker disappears in translation, that is a defect we can
 * detect rather than a silent loss of provenance.
 */
export function persianPrompt(draft, glossary = []) {
  const terms = glossary.length
    ? glossary.map((g) => `  "${g.term_en}" → "${g.term_fa}"`).join('\n')
    : '  (none supplied)';

  return [
    {
      role: 'system',
      content: [
        'You render an English reference draft into natural Persian (Farsi).',
        '',
        'RULES',
        '1. Translate meaning, not word order. The result must read as though it',
        '   were written in Persian, not translated into it.',
        '2. Keep every "refs" array exactly as it is. Do not add, remove, merge or',
        '   renumber them. They are checked automatically.',
        '3. Keep the paragraph structure: same sections, same number of paragraphs,',
        '   in the same order.',
        '4. Do not add facts, examples, caveats or flourishes that are not in the',
        '   English. Do not remove any either.',
        '5. Numbers stay as digits. Convert imperial units to metric only when the',
        '   English gives both; otherwise keep the original and its unit.',
        '6. Use the half-space (ZWNJ, U+200C) correctly: می‌رود, نان‌های, کتاب‌ها.',
        '7. Use the locked glossary terms exactly. They keep vocabulary consistent',
        '   across the whole site.',
        '',
        'Locked terms:',
        terms,
        '',
        'Return JSON with the same shape as the input, with title_fa and summary_fa',
        'added and all text fields in Persian.',
      ].join('\n'),
    },
    {
      role: 'user',
      content: JSON.stringify(draft, null, 2),
    },
  ];
}

/**
 * Asked only after the mechanical validator has flagged something, so the
 * model gets a specific defect to fix rather than a vague instruction to
 * improve.
 */
export function repairPrompt(draft, findings, sources) {
  return [
    {
      role: 'system',
      content: [
        'A draft failed its citation check. Fix exactly the listed problems.',
        '',
        'For each finding:',
        '- "unknown_source": remove the invented citation. If the claim then has',
        '  no support, delete the claim. Do not substitute another source number.',
        '- "unsupported_number": either change the value to what the cited source',
        '  actually says, or remove the claim. Never keep an unsupported figure.',
        '- "uncited_claim": add the correct refs, or delete the sentence.',
        '',
        `You may only cite [1] to [${sources.length}].`,
        'Change nothing else. Return the full corrected JSON in the same shape.',
      ].join('\n'),
    },
    {
      role: 'user',
      content: [
        'Findings:',
        findings.map((f) => `- [${f.code}] ${f.anchor ?? 'document'}: ${f.message}`).join('\n'),
        '',
        'Draft:',
        JSON.stringify(draft, null, 2),
      ].join('\n'),
    },
  ];
}

export function imagePrompt(titleEn, kind) {
  return kind === 'recipe'
    ? `A finished dish of ${titleEn}, photographed from above on a plain ceramic plate, ` +
      'soft natural window light, shallow depth of field, no text, no hands, no branding, ' +
      'realistic home cooking rather than styled restaurant plating.'
    : `A clean, uncluttered photograph illustrating ${titleEn}, soft natural light, ` +
      'no text, no people, no branding.';
}
