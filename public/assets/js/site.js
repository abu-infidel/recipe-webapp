/**
 * Site-wide behaviour: theme toggle and the instant-search box.
 *
 * No cookies. The theme choice is a per-device preference in localStorage;
 * the search box talks only to this site's own JSON endpoint.
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------- theme

  var THEME_KEY = 'theme';

  function readTheme() {
    try { return window.localStorage.getItem(THEME_KEY); } catch (e) { return null; }
  }
  function writeTheme(value) {
    try { window.localStorage.setItem(THEME_KEY, value); } catch (e) { /* nothing to do */ }
  }

  var toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    var apply = function (theme) {
      if (theme) document.documentElement.setAttribute('data-theme', theme);
      else document.documentElement.removeAttribute('data-theme');

      var dark = theme === 'dark' ||
        (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
      toggle.setAttribute('aria-label', dark ? 'حالت روشن' : 'حالت تیره');
      toggle.setAttribute('aria-pressed', String(dark));
    };

    apply(readTheme());

    toggle.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme');
      var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var next = current ? (current === 'dark' ? 'light' : 'dark') : (systemDark ? 'light' : 'dark');

      writeTheme(next);
      apply(next);
    });
  }

  // -------------------------------------------------------- instant search

  var box = document.querySelector('[data-search]');
  if (!box) return;

  var input = box.querySelector('input[type="search"]');
  var list = box.querySelector('[data-suggestions]');
  if (!input || !list) return;

  var timer = null;
  var controller = null;
  var selected = -1;

  function clear() {
    list.innerHTML = '';
    selected = -1;
    input.setAttribute('aria-expanded', 'false');
  }

  function render(items) {
    if (!items.length) { clear(); return; }

    list.innerHTML = items.map(function (item) {
      var href = '/' + String(item.field_path).split('/').map(encodeURIComponent).join('/') +
        '/' + encodeURIComponent(item.slug);
      return '<li role="option" aria-selected="false"><a href="' + href + '">' +
        escapeHtml(item.title_fa) +
        '<small>' + escapeHtml(String(item.field_path).split('/').join(' ← ')) + '</small>' +
        '</a></li>';
    }).join('');

    selected = -1;
    input.setAttribute('aria-expanded', 'true');
  }

  function highlight(delta) {
    var options = list.querySelectorAll('li');
    if (!options.length) return;

    selected = (selected + delta + options.length) % options.length;
    for (var i = 0; i < options.length; i++) {
      options[i].setAttribute('aria-selected', String(i === selected));
    }
  }

  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    var query = input.value.trim();

    if (query.length < 2) { clear(); return; }

    // Debounced so a fast typist does not fire a request per keystroke; the
    // host is small and this endpoint is rate limited like any other.
    timer = window.setTimeout(function () {
      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

      window.fetch('/api/search.json?q=' + encodeURIComponent(query), {
        signal: controller ? controller.signal : undefined,
        headers: { 'Accept': 'application/json' }
      })
        .then(function (response) { return response.ok ? response.json() : { suggestions: [] }; })
        .then(function (data) { render(data.suggestions || []); })
        .catch(function () { /* aborted or offline — leave the last result up */ });
    }, 180);
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') { event.preventDefault(); highlight(1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(-1); }
    else if (event.key === 'Escape') { clear(); }
    else if (event.key === 'Enter' && selected >= 0) {
      var link = list.querySelectorAll('li')[selected].querySelector('a');
      if (link) { event.preventDefault(); window.location.href = link.href; }
    }
  });

  document.addEventListener('click', function (event) {
    if (!box.contains(event.target)) clear();
  });

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
})();

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

  var articleId = Number(document.body.getAttribute('data-article-id') || 0);
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
