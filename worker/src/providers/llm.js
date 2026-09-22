/**
 * Chat completions against any OpenAI-compatible endpoint.
 *
 * DeepSeek is the default, but nothing here is DeepSeek-specific: the base URL
 * and model name are configuration, so moving to OpenAI, a local model or an
 * Iranian gateway is an .env change.
 */

import { config } from '../config.js';

// Approximate per-million-token prices, only used for the budget estimate
// shown in the admin panel. Wrong numbers here cost nothing but a bad guess.
const PRICING = {
  'deepseek-chat': { input: 0.27, output: 1.1 },
  'deepseek-reasoner': { input: 0.55, output: 2.19 },
  'gpt-4o-mini': { input: 0.15, output: 0.6 },
};

export class LlmError extends Error {}

export async function chat(messages, { model, temperature = 0.2, json = true, maxTokens } = {}) {
  if (!config.llm.apiKey) {
    throw new LlmError('LLM_API_KEY is not set.');
  }

  const chosen = model ?? config.llm.model;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.limits.requestTimeoutMs);

  try {
    const response = await fetch(`${config.llm.baseUrl}/chat/completions`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${config.llm.apiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        model: chosen,
        messages,
        temperature,
        max_tokens: maxTokens ?? config.llm.maxTokens,
        ...(json ? { response_format: { type: 'json_object' } } : {}),
      }),
      signal: controller.signal,
    });

    if (!response.ok) {
      const text = await response.text();
      throw new LlmError(`${chosen} returned ${response.status}: ${text.slice(0, 400)}`);
    }

    const data = await response.json();
    const content = data?.choices?.[0]?.message?.content;
    if (typeof content !== 'string') {
      throw new LlmError('Model response had no content.');
    }

    return {
      content,
      usage: data.usage ?? {},
      costMicros: estimateCostMicros(chosen, data.usage),
      model: chosen,
    };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Parse a JSON response, tolerating the fenced code block models sometimes
 * wrap it in despite being asked for raw JSON.
 */
export function parseJson(content, what = 'response') {
  let text = content.trim();

  const fence = text.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/);
  if (fence) text = fence[1].trim();

  try {
    return JSON.parse(text);
  } catch (error) {
    throw new LlmError(`Could not parse the ${what} as JSON: ${error.message}\n${text.slice(0, 400)}`);
  }
}

function estimateCostMicros(model, usage) {
  const price = PRICING[model];
  if (!price || !usage) return 0;

  const input = (usage.prompt_tokens ?? 0) / 1_000_000 * price.input;
  const output = (usage.completion_tokens ?? 0) / 1_000_000 * price.output;

  return Math.round((input + output) * 1_000_000);
}
