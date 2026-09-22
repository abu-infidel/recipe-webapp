/**
 * Review screen wiring.
 *
 * Clicking a citation marker highlights the paragraph and scrolls the source
 * pane to the source it cites, so checking a claim against its evidence is one
 * click rather than a hunt.
 */
(function () {
  'use strict';

  var draft = document.querySelector('[data-draft]');
  if (!draft) return;

  function clearHighlights() {
    document.querySelectorAll('.source.is-highlighted').forEach(function (el) {
      el.classList.remove('is-highlighted');
    });
    document.querySelectorAll('.draft-body p.is-linked').forEach(function (el) {
      el.classList.remove('is-linked');
    });
  }

  draft.addEventListener('click', function (event) {
    var marker = event.target.closest('.cite');
    if (!marker) return;

    event.preventDefault();

    var href = marker.getAttribute('href') || '';
    var number = href.replace('#ref-', '');
    var source = document.getElementById('source-' + number);

    clearHighlights();

    var paragraph = marker.closest('p');
    if (paragraph) paragraph.classList.add('is-linked');

    if (source) {
      source.classList.add('is-highlighted');
      source.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  // "Go to paragraph" links from the findings list.
  document.querySelectorAll('[data-jump]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var target = document.getElementById(link.getAttribute('data-jump'));
      if (!target) return;

      event.preventDefault();
      clearHighlights();
      target.classList.add('is-flagged');
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  // Mark every paragraph a finding refers to, so problems are visible in the
  // body text rather than only in the list above it.
  document.querySelectorAll('.finding [data-jump]').forEach(function (link) {
    var target = document.getElementById(link.getAttribute('data-jump'));
    if (target) target.classList.add('is-flagged');
  });
})();
