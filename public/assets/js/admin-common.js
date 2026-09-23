/**
 * Behaviour shared by every admin page.
 *
 * The Content-Security-Policy is script-src 'self', which blocks inline event
 * handlers such as onsubmit="return confirm(...)". Those used to fail
 * silently — the confirmation never appeared and the form submitted anyway,
 * so "Delete field" deleted on the first click. Confirmation prompts are
 * declared with data-confirm and wired up here instead.
 */
(function () {
  'use strict';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  }, true);
})();
