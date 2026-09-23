/**
 * Cross-checks the client-side Persian implementation against the PHP one.
 *
 * The fixture is generated from PHP (the source of truth). If this fails, the
 * two implementations have drifted and the instant-search box will suggest
 * articles the server cannot then find.
 *
 *   node tools/tests/run-js.js
 */
'use strict';

const assert = require('assert');
const path = require('path');

const PersianText = require(path.join(__dirname, '../../public/assets/core/persian.js'));
const fixture = require(path.join(__dirname, 'fixtures/persian.json'));

let passed = 0;
const failures = [];

function check(label, input, expected, actual) {
  const ok = JSON.stringify(expected) === JSON.stringify(actual);
  if (ok) {
    passed++;
    return;
  }
  failures.push(
    `  ${label}(${JSON.stringify(input)})\n` +
    `      php: ${JSON.stringify(expected)}\n` +
    `      js:  ${JSON.stringify(actual)}`
  );
}

for (const c of fixture.normalize) {
  check('normalize', c.in, c.out, PersianText.normalize(c.in));
}
for (const c of fixture.tokenize) {
  check('tokenize', c.in, c.out, PersianText.tokenize(c.in));
}

// Round-trip checks that do not depend on the fixture.
assert.strictEqual(PersianText.toPersianDigits('1403'), '۱۴۰۳');
assert.strictEqual(PersianText.toAsciiDigits('۱۴۰۳'), '1403');
passed += 2;

// The citation validator: the worker's copy against the shared fixture the
// PHP copy is also tested with (CitationValidatorTest).
async function citationParity() {
  const { validateDraft } = await import(path.join(__dirname, '../../worker/src/pipeline/validate.js'));
  const cases = require(path.join(__dirname, 'fixtures/citations.json')).cases;
  let ok = 0;
  const drift = [];
  for (const c of cases) {
    const got = validateDraft(c.draft, c.sources).map((f) => `${f.code}@${f.anchor ?? 'null'}`);
    if (JSON.stringify(got) === JSON.stringify(c.expect)) ok++;
    else drift.push(`  ${c.name}\n      expected: ${JSON.stringify(c.expect)}\n      worker:   ${JSON.stringify(got)}`);
  }
  if (drift.length) {
    console.log(`  \x1b[31mFAIL\x1b[0m CitationValidator (php/js parity)  ${ok} passed, ${drift.length} failed\n`);
    console.log(drift.join('\n\n'));
    process.exit(1);
  }
  console.log(`  \x1b[32mPASS\x1b[0m CitationValidator (php/js parity)  ${ok} passed`);
}

if (failures.length) {
  console.log(`  \x1b[31mFAIL\x1b[0m PersianText (php/js parity)  ${passed} passed, ${failures.length} failed\n`);
  console.log('\x1b[31mDrift between PHP and JS:\x1b[0m\n');
  console.log(failures.join('\n\n'));
  console.log('');
  process.exit(1);
}

console.log(`  \x1b[32mPASS\x1b[0m PersianText (php/js parity)  ${passed} passed`);

citationParity();
