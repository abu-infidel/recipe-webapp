/**
 * Fetches a page and reduces it to readable text.
 *
 * Mozilla Readability is used when it is installed, because it is markedly
 * better at telling an article from the site furniture around it. The
 * fallback keeps the worker usable on a VPS where the install failed, which
 * matters when npm is slow or filtered.
 */

import { config } from '../config.js';

let Readability = null;
let JSDOM = null;

try {
  ({ Readability } = await import('@mozilla/readability'));
  ({ JSDOM } = await import('jsdom'));
} catch {
  // Fallback path; extractFallback below handles it.
}

export async function fetchAndExtract(url, { timeoutMs = 30000 } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      signal: controller.signal,
      redirect: 'follow',
      headers: {
        // Identify honestly. This worker is a research client, not a
        // browser pretending to be a person.
        'User-Agent': 'RecipeResearchBot/1.0 (+contact via site)',
        'Accept': 'text/html,application/xhtml+xml',
        'Accept-Language': 'en,fa;q=0.8',
      },
    });

    const contentType = response.headers.get('content-type') ?? '';
    if (!response.ok || !contentType.includes('html')) {
      return {
        url, ok: false, httpStatus: response.status,
        title: '', author: '', publishedDate: '', text: '',
      };
    }

    const html = await response.text();

    // A page far larger than any article is a sign of something we do not
    // want to spend memory parsing.
    if (html.length > 5_000_000) {
      return { url, ok: false, httpStatus: response.status, title: '', author: '', publishedDate: '', text: '' };
    }

    const extracted = Readability && JSDOM
      ? extractWithReadability(html, url)
      : extractFallback(html);

    return {
      url,
      ok: extracted.text.length > 200,
      httpStatus: response.status,
      ...extracted,
    };
  } catch (error) {
    return {
      url, ok: false, httpStatus: 0, title: '', author: '', publishedDate: '',
      text: '', error: String(error?.message ?? error),
    };
  } finally {
    clearTimeout(timer);
  }
}

function extractWithReadability(html, url) {
  try {
    const dom = new JSDOM(html, { url });
    const article = new Readability(dom.window.document).parse();

    if (article?.textContent) {
      return {
        title: (article.title ?? '').trim(),
        author: (article.byline ?? '').trim(),
        publishedDate: findPublishedDate(html),
        text: normaliseWhitespace(article.textContent),
      };
    }
  } catch {
    // Fall through to the simpler extractor.
  }

  return extractFallback(html);
}

/** Tag-stripping fallback. Cruder, but never throws and needs nothing. */
function extractFallback(html) {
  let text = html
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<nav[\s\S]*?<\/nav>/gi, ' ')
    .replace(/<header[\s\S]*?<\/header>/gi, ' ')
    .replace(/<footer[\s\S]*?<\/footer>/gi, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ');

  const titleMatch = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);

  text = text.replace(/<[^>]+>/g, ' ');
  text = decodeEntities(text);

  return {
    title: titleMatch ? decodeEntities(titleMatch[1]).trim() : '',
    author: '',
    publishedDate: findPublishedDate(html),
    text: normaliseWhitespace(text),
  };
}

function findPublishedDate(html) {
  const patterns = [
    /<meta[^>]+property=["']article:published_time["'][^>]+content=["']([^"']+)["']/i,
    /<meta[^>]+name=["']date["'][^>]+content=["']([^"']+)["']/i,
    /"datePublished"\s*:\s*"([^"]+)"/i,
  ];

  for (const pattern of patterns) {
    const match = html.match(pattern);
    if (match) return match[1].slice(0, 10);
  }

  return '';
}

function decodeEntities(text) {
  return text
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)));
}

function normaliseWhitespace(text) {
  return text.replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}
