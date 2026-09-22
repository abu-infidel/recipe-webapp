/**
 * Runs the pipeline against recorded fixtures.
 *
 * Spends nothing and needs no keys, so the whole chain — synthesis shape,
 * citation validation, repair, Persian rendering, HTML output — can be
 * exercised before a single API call is paid for.
 *
 *   node src/index.js --dry-run --topic "قورمه سبزی"
 */

import { validateDraft } from './pipeline/validate.js';
import { renderHtml } from './pipeline/render.js';

const FIXTURE_SOURCES = [
  {
    url: 'https://www.cooksillustrated.com/articles/braising-fundamentals',
    title: 'Braising Fundamentals',
    extracted_text:
      'Collagen begins converting to gelatin at around 71C, but the process is slow; ' +
      'holding the meat between 85C and 95C for two to three hours produces the most tender result.',
  },
  {
    url: 'https://www.fsis.usda.gov/food-safety/safe-minimum-internal-temperature-chart',
    title: 'Safe Minimum Internal Temperature Chart',
    extracted_text:
      'Beef, pork, veal and lamb steaks and roasts: 145F with a three-minute rest. ' +
      'Ground meats: 160F. All poultry: 165F.',
  },
];

// What a well-behaved synthesis looks like, plus two deliberate defects the
// validator must catch.
const FIXTURE_DRAFT = {
  title_fa: 'قورمه سبزی',
  summary_fa: 'خورش سبزی با لوبیا قرمز و لیمو عمانی.',
  sections: [
    {
      heading: 'گوشت',
      paragraphs: [
        { text: 'نگه‌داشتن گوشت بین ۸۵ تا ۹۵ درجه به مدت دو تا سه ساعت بهترین نتیجه را می‌دهد.', refs: [1] },
        { text: 'دمای مرکزی ایمن ۶۳ درجه سانتی‌گراد است.', refs: [2] },
      ],
    },
    {
      heading: 'بخش مشکل‌دار',
      paragraphs: [
        { text: 'گوشت را تا ۲۵۰ درجه حرارت دهید.', refs: [1] },   // unsupported number
        { text: 'این ادعا منبع جعلی دارد.', refs: [9] },            // invented source
      ],
    },
  ],
  key_quotes: [{ ref: 1, quote: 'holding the meat between 85C and 95C' }],
  gaps: ['منابع درباره زمان سرخ‌کردن سبزی چیزی نگفته‌اند.'],
};

export async function runDry(topic, { log }) {
  log(`dry run for "${topic}" — no API calls, nothing spent`);
  log('');

  log(`${FIXTURE_SOURCES.length} fixture sources:`);
  FIXTURE_SOURCES.forEach((source, i) => log(`  [${i + 1}] ${source.title}`));
  log('');

  log('validating the draft...');
  const findings = validateDraft(FIXTURE_DRAFT, FIXTURE_SOURCES);

  const errors = findings.filter((f) => f.severity === 'error');
  const warnings = findings.filter((f) => f.severity === 'warn');

  for (const finding of findings) {
    log(`  [${finding.code}] ${finding.anchor ?? 'document'}: ${finding.message}`,
        finding.severity === 'error' ? 'error' : 'warn');
  }
  log('');

  // The fixture contains known defects; finding them is the pass condition.
  const caughtInventedSource = errors.some((f) => f.code === 'unknown_source');
  const caughtBadNumber = warnings.some((f) => f.code === 'unsupported_number');

  log(caughtInventedSource
    ? '  ✓ the invented citation was caught'
    : '  ✗ THE INVENTED CITATION WAS MISSED', caughtInventedSource ? 'done' : 'error');

  log(caughtBadNumber
    ? '  ✓ the unsupported number was caught'
    : '  ✗ THE UNSUPPORTED NUMBER WAS MISSED', caughtBadNumber ? 'done' : 'error');
  log('');

  const { html, citations } = renderHtml(FIXTURE_DRAFT);
  log(`rendered ${html.length} bytes of HTML with ${citations.length} citation anchors`);
  log('');
  log('--- body_html ---');
  console.log(html);
  log('--- end ---');

  if (!caughtInventedSource || !caughtBadNumber) {
    process.exitCode = 1;
  }
}
