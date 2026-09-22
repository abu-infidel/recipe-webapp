/**
 * The research pipeline.
 *
 * Each stage is a separate job on the site's queue, so a failure retries only
 * its own step instead of throwing away the research and starting again. The
 * worker handles whichever stage it is handed and names the next one; the
 * site owns the sequencing.
 *
 *   plan -> search -> fetch -> synthesize -> validate -> persian -> image
 *        -> link -> push
 */

import { chat, parseJson } from '../providers/llm.js';
import { search, trustTier } from '../providers/search.js';
import { fetchAndExtract } from '../providers/extract.js';
import { generateImage } from '../providers/image.js';
import {
  planPrompt, synthesisPrompt, persianPrompt, repairPrompt, imagePrompt,
} from '../prompts/index.js';
import { validateDraft } from './validate.js';
import { renderHtml } from './render.js';
import { config } from '../config.js';

export const stages = {
  plan, search: searchStage, fetch: fetchStage, synthesize,
  validate: validateStage, persian, image: imageStage, link, push,
};

// ------------------------------------------------------------------- plan

async function plan(job, { log }) {
  const topic = job.payload.topic ?? job.topic;
  const kind = job.payload.kind ?? 'guide';

  log(`planning research for "${topic}"`);
  const response = await chat(planPrompt(topic, kind), { temperature: 0.3 });
  const parsed = parseJson(response.content, 'plan');

  if (!Array.isArray(parsed.questions) || parsed.questions.length === 0) {
    throw new Error('The plan contained no research questions.');
  }

  log(`${parsed.outline?.length ?? 0} sections, ${parsed.questions.length} questions`);

  return {
    result: { plan: parsed, model: response.model },
    costMicros: response.costMicros,
    nextStage: 'search',
    nextPayload: { ...job.payload, topic, kind, plan: parsed },
  };
}

// ----------------------------------------------------------------- search

async function searchStage(job, { log }) {
  const questions = job.payload.plan?.questions ?? [];
  const seen = new Map();

  for (const question of questions) {
    log(`searching: ${question}`);

    let results = [];
    try {
      results = await search(question, { limit: 5 });
    } catch (error) {
      log(`search failed for one question: ${error.message}`, 'warn');
      continue;
    }

    for (const result of results) {
      const tier = trustTier(result.url);
      if (tier >= 5) continue;               // content farms never make the cut

      const existing = seen.get(result.url);
      if (existing) {
        existing.questions.push(question);
        continue;
      }

      seen.set(result.url, { ...result, trustTier: tier, questions: [question] });
    }
  }

  // Best sources first: trust tier, then how many questions it answers.
  const candidates = [...seen.values()]
    .sort((a, b) => a.trustTier - b.trustTier || b.questions.length - a.questions.length)
    .slice(0, config.limits.maxSources);

  if (candidates.length === 0) {
    throw new Error('No usable sources found. Check the search provider and its key.');
  }

  log(`${candidates.length} candidate sources`);

  return {
    result: { candidates: candidates.map((c) => ({ url: c.url, title: c.title, trustTier: c.trustTier })) },
    nextStage: 'fetch',
    nextPayload: { ...job.payload, candidates },
  };
}

// ------------------------------------------------------------------ fetch

async function fetchStage(job, { log }) {
  const candidates = job.payload.candidates ?? [];
  const sources = [];

  for (const candidate of candidates) {
    // Tavily can return the page body with the search result, which saves a
    // round trip and a fetch the site might refuse.
    if (candidate.rawContent && candidate.rawContent.length > 500) {
      sources.push({
        url: candidate.url,
        title: candidate.title,
        author: '',
        published_date: '',
        lang: 'en',
        http_status: 200,
        extracted_text: candidate.rawContent.slice(0, 40000),
        trust_tier: candidate.trustTier,
      });
      continue;
    }

    const page = await fetchAndExtract(candidate.url);
    if (!page.ok) {
      log(`could not extract ${candidate.url} (${page.httpStatus})`, 'warn');
      continue;
    }

    sources.push({
      url: page.url,
      title: page.title || candidate.title,
      author: page.author,
      published_date: page.publishedDate,
      lang: 'en',
      http_status: page.httpStatus,
      extracted_text: page.text.slice(0, 40000),
      trust_tier: candidate.trustTier,
    });
  }

  if (sources.length < 2) {
    throw new Error(`Only ${sources.length} source(s) could be read; at least 2 are needed.`);
  }

  log(`extracted ${sources.length} sources`);

  // The site stores these and returns their ids; the marker a paragraph cites
  // is a source's 1-based position in this list.
  return {
    result: { sources },
    nextStage: 'synthesize',
    nextPayload: { ...job.payload, sources },
    carriesSourceIds: true,
  };
}

// ------------------------------------------------------------- synthesize

async function synthesize(job, { log }) {
  const { topic, plan: researchPlan, sources, sourceIds } = job.payload;

  const forModel = sources.map((s) => ({
    url: s.url, title: s.title, publishedDate: s.published_date, text: s.extracted_text,
  }));

  log(`synthesising from ${forModel.length} sources`);

  const response = await chat(
    synthesisPrompt(topic, researchPlan.outline ?? [], forModel, researchPlan.risk_notes ?? []),
    { temperature: 0.15, maxTokens: config.llm.maxTokens }
  );

  const draft = parseJson(response.content, 'synthesis');

  if (!Array.isArray(draft.sections) || draft.sections.length === 0) {
    throw new Error('The synthesis produced no sections.');
  }

  log(`drafted ${draft.sections.length} sections, confidence ${draft.confidence ?? 'unknown'}`);

  return {
    result: { draft, model: response.model },
    costMicros: response.costMicros,
    nextStage: 'validate',
    nextPayload: { ...job.payload, draft, sourceIds },
  };
}

// --------------------------------------------------------------- validate

async function validateStage(job, { log, client }) {
  const { draft, sources, sourceIds } = job.payload;

  let current = draft;
  let findings = validateDraft(current, sources);
  let repairs = 0;

  // Up to two repair rounds. The model gets the specific defect, not a vague
  // instruction to try harder.
  while (findings.some((f) => f.severity === 'error') && repairs < 2) {
    repairs++;
    log(`repair round ${repairs}: ${findings.length} finding(s)`, 'warn');

    const response = await chat(repairPrompt(current, findings, sources), { temperature: 0.1 });
    current = parseJson(response.content, 'repair');
    findings = validateDraft(current, sources);
  }

  // The site's own check is the one that counts: it runs against the sources
  // table rather than anything this worker asserts.
  const serverVerdict = await client.validate(current, sourceIds ?? []);

  if (!serverVerdict.ok) {
    log(`the site rejected the citations: ${serverVerdict.findings.length} finding(s)`, 'error');
    throw new Error(
      'Citation validation failed on the server: ' +
      serverVerdict.findings.map((f) => f.code).join(', ')
    );
  }

  log(`citations check out (${findings.length} non-blocking note(s))`);

  return {
    result: { findings: serverVerdict.findings, repairs },
    nextStage: 'persian',
    nextPayload: { ...job.payload, draft: current, findings: serverVerdict.findings },
  };
}

// ---------------------------------------------------------------- persian

async function persian(job, { log }) {
  const { draft, glossary = [] } = job.payload;

  log('rendering into Persian');
  const response = await chat(persianPrompt(draft, glossary), {
    temperature: 0.3,
    maxTokens: config.llm.maxTokens,
  });

  const rendered = parseJson(response.content, 'Persian rendering');

  // A marker lost in translation is a silent loss of provenance, so it is
  // checked rather than hoped for.
  const before = countRefs(draft);
  const after = countRefs(rendered);

  if (after < before) {
    throw new Error(`Translation dropped citations: ${before} before, ${after} after.`);
  }

  if (!rendered.title_fa) {
    throw new Error('The Persian rendering has no title.');
  }

  log(`rendered, ${after} citation markers preserved`);

  return {
    result: { rendered, model: response.model },
    costMicros: response.costMicros,
    nextStage: config.image.provider === 'none' ? 'push' : 'image',
    nextPayload: { ...job.payload, rendered },
  };
}

// ------------------------------------------------------------------ image

async function imageStage(job, { log }) {
  const { draft, rendered, kind } = job.payload;

  let image = null;
  try {
    log('generating hero image');
    image = await generateImage(imagePrompt(draft.title_en ?? rendered.title_fa, kind));
  } catch (error) {
    // An article without a picture is fine; a pipeline that stalls is not.
    log(`image generation failed, continuing without one: ${error.message}`, 'warn');
  }

  return {
    result: { image: image ? { bytes: image.data.length } : null },
    nextStage: 'push',
    nextPayload: { ...job.payload, image },
  };
}

// ------------------------------------------------------------------- link

async function link(job) {
  return { result: {}, nextStage: 'push', nextPayload: job.payload };
}

// ------------------------------------------------------------------- push

async function push(job, { log }) {
  const { rendered, sourceIds, kind, field_id: fieldId, findings = [] } = job.payload;

  const { html, citations } = renderHtml(rendered);

  log(`pushing draft "${rendered.title_fa}"`);

  return {
    result: {
      field_id: fieldId,
      kind,
      title_fa: rendered.title_fa,
      summary_fa: rendered.summary_fa ?? '',
      body_html: html,
      draft: rendered,
      recipe: rendered.recipe ?? null,
      source_ids: sourceIds ?? [],
      citations,
      findings,
      model: config.llm.model,
    },
    nextStage: null,
  };
}

function countRefs(draft) {
  let total = 0;
  for (const section of draft.sections ?? []) {
    for (const paragraph of section.paragraphs ?? []) {
      total += (paragraph.refs ?? []).length;
    }
  }
  return total;
}
