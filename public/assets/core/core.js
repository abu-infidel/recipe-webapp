/**
 * Core behaviour. Not part of any theme.
 *
 * Everything here protects something a redesign must not be able to break by
 * accident: offline reading, anonymous counting, and ad accounting. It is
 * driven entirely by markup hooks, so a theme opts in by emitting them:
 *
 *   <meta name="article-id">      added to <head> by core on article pages
 *   [data-ad-slot]                ad markup, supplied whole by core
 *   [data-save-offline]           a button with data-urls='["/a","/b"]'
 *
 * A theme adds its own script for presentation; it never needs to edit this.
 */

/**
 * Service worker registration and the "save this section offline" button.
 *
 * Separate IIFE so a failure here cannot break the theme toggle or the search
 * box above it.
 */
(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) return;

  // Registered after load so it never competes with the first paint on a slow
  // connection, which is the common case here.
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () {
      // No service worker means no offline reading. The site still works.
    });
  });

  var button = document.querySelector('[data-save-offline]');
  if (!button) return;

  button.addEventListener('click', function () {
    var urls;
    try {
      urls = JSON.parse(button.getAttribute('data-urls') || '[]');
    } catch (e) {
      return;
    }

    if (!urls.length || !navigator.serviceWorker.controller) return;

    button.disabled = true;
    button.textContent = 'در حال ذخیره…';

    navigator.serviceWorker.controller.postMessage({ type: 'cache-urls', urls: urls });

    navigator.serviceWorker.addEventListener('message', function handler(event) {
      if (event.data && event.data.type === 'cached') {
        navigator.serviceWorker.removeEventListener('message', handler);
        button.textContent = 'برای خواندن آفلاین ذخیره شد';
      }
    });
  });
})();

/**
 * Anonymous counters and house ads.
 *
 * A view is counted once per page load. An ad impression is counted only once
 * the creative has been at least half on screen for a second, which is the
 * usual definition of an impression and far more honest than counting
 * renders. Each beacon carries a counter name and an id — no cookie, no
 * identifier, nothing about the reader.
 */
(function () {
  'use strict';

  function beacon(type, id) {
    var body = new URLSearchParams();
    body.set('t', type);
    body.set('id', String(id));

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/beacon', body);
    } else {
      window.fetch('/api/beacon', { method: 'POST', body: body, keepalive: true }).catch(function () {});
    }
  }

  // --------------------------------------------------------------- views

  var meta = document.querySelector('meta[name="article-id"]');
  var articleId = Number(meta ? meta.getAttribute('content') : 0);
  if (articleId > 0) {
    // Deferred until the page is actually visible, so a tab opened in the
    // background and closed unseen is not counted.
    var sendView = function () {
      if (document.visibilityState === 'visible') {
        beacon('view', articleId);
        document.removeEventListener('visibilitychange', sendView);
      }
    };
    document.addEventListener('visibilitychange', sendView);
    sendView();
  }

  // ------------------------------------------------------------ house ads

  var slots = document.querySelectorAll('[data-ad-slot]');

  Array.prototype.forEach.call(slots, function (slot) {
    var data = slot.querySelector('[data-ad-creatives]');
    if (!data) return;

    var creatives;
    try {
      creatives = JSON.parse(data.textContent) || [];
    } catch (e) {
      return;
    }
    if (!creatives.length) return;

    var creative = pickWeighted(creatives);
    slot.appendChild(render(creative));
    slot.hidden = false;

    if (!('IntersectionObserver' in window)) {
      beacon('ad', creative.id);
      return;
    }

    var timer = null;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
          timer = window.setTimeout(function () {
            beacon('ad', creative.id);
            observer.disconnect();
          }, 1000);
        } else if (timer) {
          window.clearTimeout(timer);
          timer = null;
        }
      });
    }, { threshold: [0, 0.5] });

    observer.observe(slot);
  });

  function pickWeighted(items) {
    var total = items.reduce(function (sum, item) { return sum + Math.max(1, item.weight || 1); }, 0);
    var roll = Math.random() * total;
    for (var i = 0; i < items.length; i++) {
      roll -= Math.max(1, items[i].weight || 1);
      if (roll <= 0) return items[i];
    }
    return items[0];
  }

  /** Built with DOM methods, never innerHTML, so creative text cannot inject markup. */
  function render(creative) {
    var link = document.createElement('a');
    link.className = 'ad-house';
    link.href = creative.href;
    link.rel = 'sponsored noopener';
    link.target = '_blank';

    if (creative.image) {
      var img = document.createElement('img');
      img.src = creative.image;
      img.alt = creative.alt || '';
      img.width = 80;
      img.height = 80;
      img.loading = 'lazy';
      link.appendChild(img);
    }

    var text = document.createElement('span');
    var title = document.createElement('span');
    title.className = 'ad-house__title';
    title.textContent = creative.title;
    text.appendChild(title);

    if (creative.body) {
      var body = document.createElement('span');
      body.className = 'ad-house__body';
      body.textContent = creative.body;
      text.appendChild(body);
    }

    link.appendChild(text);
    return link;
  }
})();
