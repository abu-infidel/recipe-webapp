/**
 * Solves the proof-of-work challenge and reloads.
 *
 * Deliberately cheap for one page view and expensive for thousands: a phone
 * solves 16 leading zero bits in well under a second, but a scraper pays it
 * on every request, which is what makes bulk extraction uneconomic.
 *
 * Nothing is stored on the device. The pass is recorded server-side against a
 * salted IP hash that expires, so this needs no cookie.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-challenge]');
  if (!root || !window.crypto || !window.crypto.subtle) return;

  var nonce = root.getAttribute('data-nonce');
  var difficulty = parseInt(root.getAttribute('data-difficulty'), 10) || 16;
  var status = root.querySelector('[data-challenge-status]');

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

  function attempt(counter) {
    var candidate = String(counter);

    return window.crypto.subtle
      .digest('SHA-256', encoder.encode(nonce + candidate))
      .then(function (buffer) {
        if (leadingZeroBits(new Uint8Array(buffer)) >= difficulty) {
          return candidate;
        }
        // Yield to the event loop periodically so the page stays responsive
        // and the browser does not flag the tab as unresponsive.
        if (counter % 2000 === 0) {
          return new Promise(function (resolve) {
            setTimeout(function () { resolve(attempt(counter + 1)); }, 0);
          });
        }
        return attempt(counter + 1);
      });
  }

  attempt(0)
    .then(function (solution) {
      var body = new URLSearchParams();
      body.set('nonce', nonce);
      body.set('solution', solution);

      return window.fetch('/api/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      });
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (data && data.ok) {
        window.location.reload();
      } else if (status) {
        status.textContent = 'بررسی ناموفق بود. لطفاً چند دقیقه دیگر دوباره تلاش کنید.';
      }
    })
    .catch(function () {
      if (status) {
        status.textContent = 'اتصال برقرار نشد. لطفاً دوباره تلاش کنید.';
      }
    });
})();
