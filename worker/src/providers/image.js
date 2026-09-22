/**
 * Hero image generation.
 *
 * Optional: with IMAGE_PROVIDER=none the pipeline skips straight to pushing
 * the draft. Generated images are labelled as such on the page, because a
 * reader deciding whether a dish looks right deserves to know the picture is
 * not a photograph of it.
 */

import { config } from '../config.js';

export async function generateImage(prompt) {
  if (config.image.provider === 'none') return null;
  if (!config.image.apiKey) throw new Error('IMAGE_API_KEY is not set.');

  const response = await fetch(`${config.image.baseUrl}/images/generations`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${config.image.apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: config.image.model,
      prompt,
      size: '1536x1024',
      n: 1,
    }),
  });

  if (!response.ok) {
    throw new Error(`Image API returned ${response.status}: ${(await response.text()).slice(0, 300)}`);
  }

  const data = await response.json();
  const first = data?.data?.[0];

  if (first?.b64_json) {
    return { data: Buffer.from(first.b64_json, 'base64'), prompt, mime: 'image/png' };
  }

  if (first?.url) {
    const image = await fetch(first.url);
    if (!image.ok) throw new Error(`Could not download the generated image (${image.status}).`);
    return { data: Buffer.from(await image.arrayBuffer()), prompt, mime: 'image/png' };
  }

  throw new Error('The image API returned neither image data nor a URL.');
}
