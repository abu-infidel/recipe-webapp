/**
 * The homepage tree menu.
 *
 * The whole published tree ships with the page as a JSON island, so drilling
 * down is instant and needs no network request — which matters a great deal on
 * the connections this site is built for. Nothing here talks to the server.
 *
 * URL state lives in the hash (#/cooking/persian), so back, forward and
 * sharing a deep link all work even though no page ever loads.
 */
(function () {
  'use strict';

  var root = document.getElementById('menu');
  if (!root) return;

  var payload = document.getElementById('tree-data');
  if (!payload) return;

  var tree;
  try {
    tree = JSON.parse(payload.textContent).fields || [];
  } catch (e) {
    // The no-script nested list is already in the DOM as a fallback; leaving
    // it visible is a better failure than an empty stage.
    return;
  }

  var stage = root.querySelector('[data-stage]');
  var railCrumbs = root.querySelector('[data-crumbs]');
  var backButton = root.querySelector('[data-back]');
  var filterWrap = root.querySelector('[data-level-wrap]');
  var filterInput = root.querySelector('[data-filter]');
  var noscript = root.querySelector('.menu-noscript');

  if (noscript) noscript.remove();

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var currentLevel = null;
  var path = [];
  var busy = false;

  // ---------------------------------------------------------------- lookup

  /** Walk the tree by slug, returning the node list at that path. */
  function nodesAt(segments) {
    var list = tree;
    for (var i = 0; i < segments.length; i++) {
      var found = null;
      for (var j = 0; j < list.length; j++) {
        if (list[j].slug === segments[i]) { found = list[j]; break; }
      }
      if (!found) return null;
      list = found.children || [];
    }
    return list;
  }

  /** The chain of nodes along a path, for the breadcrumb. */
  function chain(segments) {
    var out = [];
    var list = tree;
    for (var i = 0; i < segments.length; i++) {
      var found = null;
      for (var j = 0; j < list.length; j++) {
        if (list[j].slug === segments[i]) { found = list[j]; break; }
      }
      if (!found) break;
      out.push(found);
      list = found.children || [];
    }
    return out;
  }

  // --------------------------------------------------------------- density
  // The layout tiers itself off how many children a field has, so the menu
  // stays usable from three fields to fifty without anyone editing it.

  function densityFor(count) {
    if (count <= 6) return 'spacious';
    if (count <= 14) return 'medium';
    return 'compact';
  }

  // ---------------------------------------------------------------- render

  function buildTile(node, index) {
    var hasChildren = node.children && node.children.length > 0;
    var articles = Number(node.subtree_count || 0);

    var el = document.createElement(hasChildren ? 'button' : 'a');
    el.className = 'tile';
    el.style.setProperty('--i', String(index));
    if (node.accent_color) el.style.setProperty('--tile-accent', node.accent_color);
    el.setAttribute('data-slug', node.slug);
    el.setAttribute('data-title', node.title_fa || '');

    if (hasChildren) {
      el.type = 'button';
      el.addEventListener('click', function () { descend(node.slug); });
    } else {
      // A leaf goes straight to its field page — there is nothing to open.
      el.href = '/' + node.path.split('/').map(encodeURIComponent).join('/');
    }

    var parts = [];
    if (node.icon) {
      parts.push('<span class="tile__icon" aria-hidden="true">' + escapeHtml(node.icon) + '</span>');
    }
    parts.push('<span class="tile__title">' + escapeHtml(node.title_fa || node.slug) + '</span>');
    if (node.blurb_fa) {
      parts.push('<span class="tile__blurb">' + escapeHtml(node.blurb_fa) + '</span>');
    }

    var meta = '<span class="tile__meta">';
    if (articles > 0) {
      meta += '<span>' + toPersianDigits(articles) + ' نوشته</span>';
    } else {
      meta += '<span>به‌زودی</span>';
    }
    meta += '<span class="tile__chevron" aria-hidden="true">' + CHEVRON + '</span>';
    meta += '</span>';
    parts.push(meta);

    el.innerHTML = parts.join('');
    return el;
  }

  function buildLevel(nodes, direction) {
    var level = document.createElement('div');
    level.className = 'menu__level';
    level.setAttribute('data-density', densityFor(nodes.length));
    level.setAttribute('data-direction', direction);

    if (!nodes.length) {
      level.innerHTML = '<p class="menu__empty">هنوز چیزی در این بخش منتشر نشده است.</p>';
      return level;
    }

    for (var i = 0; i < nodes.length; i++) {
      level.appendChild(buildTile(nodes[i], i));
    }
    return level;
  }

  /**
   * Swap the visible level.
   *
   * Both levels occupy the same grid cell, so the outgoing set can fade along
   * the depth axis while the incoming one staggers in. The outgoing element is
   * removed on animationend rather than on a timer, so a slow device never
   * ends up with two live levels.
   */
  function show(segments, direction, focusFirst) {
    var nodes = nodesAt(segments);
    if (!nodes) { segments = []; nodes = tree; }

    path = segments;
    var level = buildLevel(nodes, direction);
    var outgoing = currentLevel;

    if (outgoing && !reduceMotion) {
      outgoing.setAttribute('data-state', 'leaving');
      outgoing.setAttribute('data-direction', direction);
      outgoing.addEventListener('animationend', function handler() {
        outgoing.removeEventListener('animationend', handler);
        if (outgoing.parentNode) outgoing.parentNode.removeChild(outgoing);
      });
      // Belt and braces: if the animation never fires (background tab), the
      // stale level would otherwise stay in the DOM and keep intercepting
      // nothing but still bloat the page.
      window.setTimeout(function () {
        if (outgoing.parentNode) outgoing.parentNode.removeChild(outgoing);
      }, 800);
    } else if (outgoing && outgoing.parentNode) {
      outgoing.parentNode.removeChild(outgoing);
    }

    if (!reduceMotion) level.setAttribute('data-state', 'entering');
    stage.appendChild(level);
    currentLevel = level;

    root.setAttribute('data-depth', String(segments.length));
    renderRail(segments);
    updateFilter(nodes.length);

    if (focusFirst) {
      var first = level.querySelector('.tile');
      if (first) first.focus({ preventScroll: true });
    }

    busy = false;
  }

  function renderRail(segments) {
    var nodes = chain(segments);

    backButton.hidden = segments.length === 0;
    railCrumbs.innerHTML = '';

    if (!segments.length) return;

    railCrumbs.appendChild(crumb('خانه', []));

    for (var i = 0; i < nodes.length; i++) {
      var sep = document.createElement('span');
      sep.className = 'rail__sep';
      sep.setAttribute('aria-hidden', 'true');
      sep.textContent = '/';
      railCrumbs.appendChild(sep);
      railCrumbs.appendChild(crumb(nodes[i].title_fa, segments.slice(0, i + 1), i === nodes.length - 1));
    }
  }

  function crumb(label, target, isCurrent) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'rail__crumb';
    button.textContent = label;
    if (isCurrent) {
      button.setAttribute('aria-current', 'page');
    } else {
      button.addEventListener('click', function () { navigate(target, 'back'); });
    }
    return button;
  }

  // ---------------------------------------------------------------- filter

  function updateFilter(count) {
    var crowded = count >= 15;
    filterWrap.setAttribute('data-crowded', crowded ? 'true' : 'false');
    if (!crowded && filterInput.value) filterInput.value = '';
  }

  if (filterInput) {
    filterInput.addEventListener('input', function () {
      var needle = normalize(filterInput.value);
      var tiles = currentLevel ? currentLevel.querySelectorAll('.tile') : [];

      for (var i = 0; i < tiles.length; i++) {
        var title = normalize(tiles[i].getAttribute('data-title') || '');
        tiles[i].hidden = needle !== '' && title.indexOf(needle) === -1;
      }
    });
  }

  // ------------------------------------------------------------ navigation

  function descend(slug) {
    if (busy) return;
    navigate(path.concat([slug]), 'forward');
  }

  function navigate(segments, direction) {
    busy = !reduceMotion;
    var hash = segments.length ? '#/' + segments.map(encodeURIComponent).join('/') : '#/';

    if (window.location.hash !== hash) {
      window.history.pushState({ path: segments }, '', hash);
    }
    show(segments, direction, direction === 'forward');
  }

  function segmentsFromHash() {
    var hash = window.location.hash.replace(/^#\/?/, '');
    if (!hash) return [];
    return hash.split('/').filter(Boolean).map(function (s) {
      try { return decodeURIComponent(s); } catch (e) { return s; }
    });
  }

  window.addEventListener('popstate', function () {
    var next = segmentsFromHash();
    show(next, next.length < path.length ? 'back' : 'forward', false);
  });

  backButton.addEventListener('click', function () {
    navigate(path.slice(0, -1), 'back');
  });

  // Escape steps back a level, which is what the gesture means here.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && path.length && document.activeElement !== filterInput) {
      navigate(path.slice(0, -1), 'back');
    }
  });

  // ---------------------------------------------------------------- helpers

  var CHEVRON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toPersianDigits(value) {
    var digits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return String(value).replace(/\d/g, function (d) { return digits[+d]; });
  }

  /** Mirrors PersianText::normalize for the filter box. See persian.js. */
  function normalize(value) {
    return window.PersianText ? window.PersianText.normalize(value) : String(value).toLowerCase();
  }

  // ------------------------------------------------------------------ start

  show(segmentsFromHash(), 'forward', false);
})();
