/**
 * Signed HTTP client for the site's worker API.
 *
 * Every request carries a bearer token plus an HMAC over the method, path,
 * timestamp, nonce and a hash of the body — so a captured request cannot be
 * replayed or altered in flight. The signature scheme must stay in step with
 * app/Support/WorkerAuth.php.
 */

import { createHmac, createHash, randomBytes } from 'node:crypto';
import { config } from './config.js';

export class SiteClient {
  constructor({ url, token, hmacSecret, workerId } = config.site) {
    this.url = url;
    this.token = token;
    this.hmacSecret = hmacSecret;
    this.workerId = workerId;
  }

  async post(path, payload) {
    const body = JSON.stringify({ worker_id: this.workerId, ...payload });
    const timestamp = Math.floor(Date.now() / 1000);
    const nonce = randomBytes(16).toString('hex');
    const bodyHash = createHash('sha256').update(body).digest('hex');

    const signature = createHmac('sha256', this.hmacSecret)
      .update(`POST\n${path}\n${timestamp}\n${nonce}\n${bodyHash}`)
      .digest('hex');

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.limits.requestTimeoutMs);

    try {
      const response = await fetch(this.url + path, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${this.token}`,
          'Content-Type': 'application/json',
          'X-Worker-Timestamp': String(timestamp),
          'X-Worker-Nonce': nonce,
          'X-Worker-Signature': signature,
          'User-Agent': 'research-worker/1.0',
        },
        body,
        signal: controller.signal,
      });

      const text = await response.text();
      let parsed = null;
      try {
        parsed = text ? JSON.parse(text) : null;
      } catch {
        throw new Error(`${path} returned non-JSON (${response.status}): ${text.slice(0, 200)}`);
      }

      if (!response.ok) {
        throw new Error(`${path} failed (${response.status}): ${parsed?.error ?? text.slice(0, 200)}`);
      }

      return parsed;
    } finally {
      clearTimeout(timer);
    }
  }

  nextJob() {
    return this.post('/api/worker/jobs/next', {});
  }

  completeJob(jobId, result, { nextStage = null, nextPayload = {}, costMicros = 0 } = {}) {
    return this.post(`/api/worker/jobs/${jobId}/result`, {
      status: 'done',
      result,
      cost_micros: costMicros,
      next_stage: nextStage,
      next_payload: nextPayload,
    });
  }

  failJob(jobId, error) {
    return this.post(`/api/worker/jobs/${jobId}/result`, {
      status: 'failed',
      error: String(error?.message ?? error).slice(0, 2000),
    });
  }

  heartbeat(jobId) {
    return this.post(`/api/worker/jobs/${jobId}/heartbeat`, {});
  }

  /**
   * Ask the site to validate a draft. The worker validates locally too, but
   * this is the check that counts: it runs against the site's own sources
   * table rather than anything the worker asserts.
   */
  validate(draft, sourceIds) {
    return this.post('/api/worker/validate', { draft, source_ids: sourceIds });
  }
}
