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

const PersianText = require(path.join(__dirname, '../../public/assets/js/persian.js'));
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

if (failures.length) {
  console.log(`  \x1b[31mFAIL\x1b[0m PersianText (php/js parity)  ${passed} passed, ${failures.length} failed\n`);
  console.log('\x1b[31mDrift between PHP and JS:\x1b[0m\n');
  console.log(failures.join('\n\n'));
  console.log('');
  process.exit(1);
}

console.log(`  \x1b[32mPASS\x1b[0m PersianText (php/js parity)  ${passed} passed`);
