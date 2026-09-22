/**
 * Web search, behind one interface.
 *
 * DeepSeek's API has no web browsing, so the worker does the searching and
 * hands the model a fixed, numbered source set. That is not a workaround — it
 * is what makes an invented citation impossible rather than merely unlikely,
 * because the model can only cite what is in front of it.
 *
 * Tavily is wired first; swapping to Brave or a self-hosted SearXNG is a
 * configuration change.
 */

import { config } from '../config.js';

export class SearchError extends Error {}

/**
 * @returns {Promise<Array<{url: string, title: string, snippet: string, score: number}>>}
 */
export async function search(query, { limit = 6 } = {}) {
  switch (config.search.provider) {
    case 'tavily':  return tavily(query, limit);
    case 'brave':   return brave(query, limit);
    case 'searxng': return searxng(query, limit);
    case 'none':    return [];
    default:
      throw new SearchError(`Unknown SEARCH_PROVIDER: ${config.search.provider}`);
  }
}

async function tavily(query, limit) {
  if (!config.search.tavilyKey) throw new SearchError('TAVILY_API_KEY is not set.');

  const response = await fetch('https://api.tavily.com/search', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      api_key: config.search.tavilyKey,
      query,
      max_results: limit,
      search_depth: 'advanced',
      include_raw_content: true,
    }),
  });

  if (!response.ok) {
    throw new SearchError(`Tavily returned ${response.status}: ${(await response.text()).slice(0, 300)}`);
  }

  const data = await response.json();

  return (data.results ?? []).map((r) => ({
    url: r.url,
    title: r.title ?? '',
    snippet: r.content ?? '',
    // Tavily can return the extracted body directly, which saves a fetch.
    rawContent: r.raw_content ?? '',
    score: r.score ?? 0,
  }));
}

async function brave(query, limit) {
  if (!config.search.braveKey) throw new SearchError('BRAVE_API_KEY is not set.');

  const url = new URL('https://api.search.brave.com/res/v1/web/search');
  url.searchParams.set('q', query);
  url.searchParams.set('count', String(limit));

  const response = await fetch(url, {
    headers: { 'Accept': 'application/json', 'X-Subscription-Token': config.search.braveKey },
  });

  if (!response.ok) {
    throw new SearchError(`Brave returned ${response.status}`);
  }

  const data = await response.json();

  return (data.web?.results ?? []).map((r) => ({
    url: r.url,
    title: r.title ?? '',
    snippet: r.description ?? '',
    rawContent: '',
    score: 0,
  }));
}

async function searxng(query, limit) {
  if (!config.search.searxngUrl) throw new SearchError('SEARXNG_URL is not set.');

  const url = new URL('/search', config.search.searxngUrl);
  url.searchParams.set('q', query);
  url.searchParams.set('format', 'json');

  const response = await fetch(url);
  if (!response.ok) throw new SearchError(`SearXNG returned ${response.status}`);

  const data = await response.json();

  return (data.results ?? []).slice(0, limit).map((r) => ({
    url: r.url,
    title: r.title ?? '',
    snippet: r.content ?? '',
    rawContent: '',
    score: r.score ?? 0,
  }));
}

/**
 * How much a domain should be trusted, 1 (best) to 5 (worst).
 *
 * Used to rank candidates and to drop the obvious content farms before the
 * model ever sees them. A recipe site is not a bad source for a recipe, but a
 * scraped-aggregator is a bad source for anything.
 */
export function trustTier(url) {
  let host;
  try {
    host = new URL(url).hostname.toLowerCase();
  } catch {
    return 5;
  }

  if (/\.(gov|edu)(\.[a-z]{2})?$/.test(host) || host.endsWith('.ac.uk')) return 1;
  if (/(^|\.)(who|fao|usda|fsis|nih|nal\.usda|nchfp\.uga)\./.test(host)) return 1;
  if (/(^|\.)(wikipedia|britannica|seriouseats|cooksillustrated|kingarthurbaking|americastestkitchen)\./.test(host)) return 2;

  if (DENYLIST.some((bad) => host.includes(bad))) return 5;

  return 3;
}

// Content farms and scraped aggregators: high volume, low reliability.
const DENYLIST = [
  'pinterest.', 'quora.', 'answers.', 'ehow.', 'wikihow.',
  'blogspot.', 'wordpress.com', 'medium.com', 'scribd.',
  'slideshare.', 'coursehero.', 'studocu.',
];
