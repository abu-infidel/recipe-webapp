/**
 * The contributor sign-in page.
 *
 * Before a code can be requested the browser solves a small proof of work
 * (the same one as the anti-scraper challenge). A person waits a fraction of
 * a second; a script trying to send thousands of texts pays for every one.
 *
 * Also wires data-confirm on forms, because the CSP forbids inline onsubmit.
 */
(function () {
  'use strict';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    var submitter = event.submitter;
    var message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  }, true);

  var form = document.querySelector('[data-pow-form]');
  if (!form) return;

  var status = form.querySelector('[data-pow-status]');
  var button = form.querySelector('[data-pow-submit]');
  var field = form.querySelector('[data-pow-solution]');
  var nonce = form.getAttribute('data-pow-nonce');
  var difficulty = parseInt(form.getAttribute('data-pow-difficulty'), 10) || 16;
  var solving = false;

  if (!window.crypto || !window.crypto.subtle || !window.TextEncoder) {
    if (status) status.textContent = 'این مرورگر برای ورود پشتیبانی نمی‌شود. لطفاً مرورگر را به‌روز کنید.';
    if (button) button.disabled = true;
    return;
  }

  var encoder = new TextEncoder();

  function leadingZeroBits(bytes) {
    var count = 0;
    for (var i = 0; i < bytes.length; i++) {
      if (bytes[i] === 0) { count += 8; continue; }
      var b = bytes[i];
      while ((b & 0x80) === 0) { count++; b <<= 1; }
      break;
    }
    return count;
  }

  function solve(counter) {
    return window.crypto.subtle.digest('SHA-256', encoder.encode(nonce + counter)).then(function (buffer) {
      if (leadingZeroBits(new Uint8Array(buffer)) >= difficulty) return String(counter);
      if (counter % 2000 === 0) {
        return new Promise(function (resolve) { setTimeout(function () { resolve(solve(counter + 1)); }, 0); });
      }
      return solve(counter + 1);
    });
  }

  form.addEventListener('submit', function (event) {
    if (field.value !== '') return;   // solved: let it go
    event.preventDefault();
    if (solving) return;
    solving = true;
    if (button) button.disabled = true;
    if (status) status.textContent = 'چند لحظه…';

    solve(0).then(function (solution) {
      field.value = solution;
      form.submit();
    }).catch(function () {
      solving = false;
      if (button) button.disabled = false;
      if (status) status.textContent = 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.';
    });
  });
})();
