#!/usr/bin/env node
/**
 * The research worker.
 *
 * Runs on a VPS outside Iran, because OpenAI and DeepSeek both refuse
 * Iranian addresses. It pulls jobs from the site, does everything that needs
 * the outside internet, and pushes finished Persian drafts back over HTTPS.
 *
 * The site never calls out. That is the point: the shared host needs no
 * outbound access at all, and the API key never lives on it.
 *
 *   node src/index.js            poll forever
 *   node src/index.js --once     handle one job and stop
 *   node src/index.js --dry-run --topic "قورمه سبزی"
 *                                run the pipeline against recorded fixtures,
 *                                spending nothing
 */

import { config } from './config.js';
import { SiteClient } from './client.js';
import { stages } from './pipeline/index.js';
import { runDry } from './dry-run.js';

const args = process.argv.slice(2);
const flag = (name) => args.includes(`--${name}`);
const option = (name, fallback = null) => {
  const index = args.indexOf(`--${name}`);
  return index !== -1 && args[index + 1] ? args[index + 1] : fallback;
};

const COLOURS = { info: '\x1b[0m', warn: '\x1b[33m', error: '\x1b[31m', done: '\x1b[32m' };

function log(message, level = 'info') {
  const time = new Date().toISOString().slice(11, 19);
  const colour = COLOURS[level] ?? COLOURS.info;
  console.log(`${colour}[${time}] ${message}\x1b[0m`);
}

async function handle(job, client) {
  const handler = stages[job.type];
  if (!handler) {
    throw new Error(`No handler for stage "${job.type}".`);
  }

  const jobLog = (message, level = 'info') => log(`  ${job.type}: ${message}`, level);

  // Long stages (synthesis in particular) can outlive the lease, so renew it
  // while the work is in flight rather than losing the job to a timeout.
  const heartbeat = setInterval(() => {
    client.heartbeat(job.id).catch(() => { /* a missed beat is not fatal */ });
  }, Math.max(30, config.limits.pollSeconds) * 1000);

  try {
    const outcome = await handler(job, { log: jobLog, client });

    // The fetch stage is special: the site stores the sources and hands back
    // their ids, which every later stage needs to cite by marker.
    const response = await client.completeJob(job.id, outcome.result, {
      nextStage: outcome.nextStage,
      nextPayload: outcome.carriesSourceIds
        ? { ...outcome.nextPayload, sourceIds: [] }
        : outcome.nextPayload,
      costMicros: outcome.costMicros ?? 0,
    });

    if (outcome.carriesSourceIds && response?.outcome?.source_ids) {
      log(`  stored ${response.outcome.source_ids.length} sources`, 'info');
    }

    if (response?.outcome?.article_id) {
      log(`  draft landed as article ${response.outcome.article_id}, awaiting review`, 'done');
    }

    return response;
  } finally {
    clearInterval(heartbeat);
  }
}

async function poll() {
  for (const [name, value] of Object.entries({
    SITE_TOKEN: config.site.token,
    SITE_HMAC_SECRET: config.site.hmacSecret,
  })) {
    if (!value) {
      log(`${name} is not set. See worker/.env.example.`, 'error');
      process.exit(1);
    }
  }

  const client = new SiteClient();
  log(`worker "${config.site.workerId}" polling ${config.site.url}`);
  log(`model ${config.llm.model} via ${config.llm.baseUrl}, search via ${config.search.provider}`);

  let idleRounds = 0;

  for (;;) {
    let job = null;

    try {
      const response = await client.nextJob();
      job = response?.job ?? null;
    } catch (error) {
      log(`could not reach the site: ${error.message}`, 'error');
      await sleep(Math.min(300, config.limits.pollSeconds * 4) * 1000);
      continue;
    }

    if (!job) {
      idleRounds++;
      if (idleRounds === 1) log('queue empty, waiting');
      if (flag('once')) return;

      // Back off while idle so an empty queue is not polled aggressively for
      // hours, but stay responsive once work appears.
      await sleep(Math.min(config.limits.pollSeconds * Math.min(idleRounds, 6), 300) * 1000);
      continue;
    }

    idleRounds = 0;
    log(`job ${job.id}: ${job.type}${job.topic ? ` — ${job.topic}` : ''}`);

    try {
      await handle(job, client);
      log(`job ${job.id} done`, 'done');
    } catch (error) {
      log(`job ${job.id} failed: ${error.message}`, 'error');
      try {
        await client.failJob(job.id, error);
      } catch (reportError) {
        log(`could not report the failure: ${reportError.message}`, 'error');
      }
    }

    if (flag('once')) return;
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// ------------------------------------------------------------------- start

if (flag('dry-run')) {
  await runDry(option('topic', 'قورمه سبزی'), { log });
} else {
  await poll().catch((error) => {
    log(error.stack ?? String(error), 'error');
    process.exit(1);
  });
}
