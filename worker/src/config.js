/**
 * Worker configuration, read once from the environment.
 *
 * Everything that could change between deployments lives here, so switching
 * model, search provider or site is an .env edit rather than a code change.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

// A tiny .env reader. A dependency for this would be one more thing to
// install on a VPS that may have a slow or filtered npm route.
function loadEnvFile() {
  try {
    const raw = readFileSync(join(here, '..', '.env'), 'utf8');
    for (const line of raw.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;

      const eq = trimmed.indexOf('=');
      if (eq === -1) continue;

      const key = trimmed.slice(0, eq).trim();
      let value = trimmed.slice(eq + 1).trim();
      if (
        (value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'"))
      ) {
        value = value.slice(1, -1);
      }
      if (!(key in process.env)) process.env[key] = value;
    }
  } catch {
    // No .env is fine when the environment is set another way (systemd, CI).
  }
}

loadEnvFile();

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function optional(name, fallback = '') {
  return process.env[name] || fallback;
}

function number(name, fallback) {
  const value = Number(process.env[name]);
  return Number.isFinite(value) ? value : fallback;
}

export const config = {
  site: {
    url: optional('SITE_URL', 'http://127.0.0.1:8080').replace(/\/$/, ''),
    token: optional('SITE_TOKEN'),
    hmacSecret: optional('SITE_HMAC_SECRET'),
    workerId: optional('WORKER_ID', 'worker-1'),
  },

  llm: {
    baseUrl: optional('LLM_BASE_URL', 'https://api.deepseek.com/v1').replace(/\/$/, ''),
    apiKey: optional('LLM_API_KEY'),
    model: optional('LLM_MODEL', 'deepseek-chat'),
    // The judge can use a different (stronger or cheaper) model.
    judgeModel: optional('LLM_JUDGE_MODEL', optional('LLM_MODEL', 'deepseek-chat')),
    reasoningModel: optional('LLM_MODEL_REASONING', 'deepseek-reasoner'),
    maxTokens: number('LLM_MAX_TOKENS', 8000),
  },

  search: {
    provider: optional('SEARCH_PROVIDER', 'tavily'),
    tavilyKey: optional('TAVILY_API_KEY'),
    braveKey: optional('BRAVE_API_KEY'),
    searxngUrl: optional('SEARXNG_URL'),
  },

  image: {
    provider: optional('IMAGE_PROVIDER', 'none'),
    apiKey: optional('IMAGE_API_KEY'),
    baseUrl: optional('IMAGE_BASE_URL', 'https://api.openai.com/v1').replace(/\/$/, ''),
    model: optional('IMAGE_MODEL', 'gpt-image-1'),
  },

  limits: {
    pollSeconds: number('POLL_SECONDS', 20),
    maxSources: number('MAX_SOURCES_PER_ARTICLE', 12),
    dailyBudgetUsd: number('DAILY_BUDGET_USD', 5),
    requestTimeoutMs: number('REQUEST_TIMEOUT_MS', 120000),
  },

  required,
};
