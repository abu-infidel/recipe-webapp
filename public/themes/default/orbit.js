/**
 * The orbit: the homepage menu.
 *
 * The current field sits in the centre as a word and its children orbit it.
 * Choosing a child that has children of its own sends it travelling to the
 * centre while its children fan out around it; choosing a leaf opens its
 * page. Going back reverses the motion.
 *
 * Everything runs from the tree island in the page, so no step makes a
 * network request — which matters on the connections this site is built for.
 *
 * Every name is a real link to its field page. The script only intercepts a
 * plain click on a name that has children; a modified click (new tab), a
 * keyboard user who wants the page, or a browser without JavaScript all get
 * the ordinary link.
 *
 * Layout is measured, not guessed: the names are rendered, their real boxes
 * measured, and the smallest comfortable ring that keeps every box clear of
 * every other is chosen. If no ring fits — too many names for the screen —
 * the level is shown as a list instead. Long Persian names therefore never
 * overlap, whatever the device.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-orbit]');
  var island = document.getElementById('tree-data');
  if (!root || !island) return;

  var tree;
  try {
    tree = JSON.parse(island.textContent).fields || [];
  } catch (e) {
    return;   // the static list of links is already on the page
  }

  var stage = root.querySelector('[data-orbit-stage]');
  var nav = root.querySelector('[data-orbit-nav]');
  var upButton = root.querySelector('[data-orbit-up]');
  var trail = root.querySelector('[data-orbit-trail]');
  var announcer = root.querySelector('[data-orbit-announce]');
  var rootLabel = stage.getAttribute('data-root-label') || '';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var EASE_OUT = 'cubic-bezier(0.16, 1, 0.3, 1)';
  var EASE = 'cubic-bezier(0.22, 0.61, 0.36, 1)';
  var GAP = 10;          // minimum clear space between two names, px

  var path = [];         // slugs from the top to the field in the centre
  var current = null;    // { el, center, nodes: [{el, data}], mode }
  var busy = false;
  var resyncWhenIdle = false;   // the URL changed while an animation was running

  // ================================================================ data

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

  function childrenOf(segments) {
    if (!segments.length) return tree;
    var nodes = chain(segments);
    return nodes.length === segments.length ? (nodes[nodes.length - 1].children || []) : null;
  }

  /** The nearest accent colour on the way down to this field. */
  function accentFor(segments) {
    var nodes = chain(segments);
    for (var i = nodes.length - 1; i >= 0; i--) {
      if (nodes[i].accent) return nodes[i].accent;
    }
    return '';
  }

  // ============================================================ building

  function buildLevel(segments) {
    var nodes = childrenOf(segments) || [];
    var trailNodes = chain(segments);
    var here = trailNodes[trailNodes.length - 1] || null;

    var el = document.createElement('div');
    el.className = 'orbit__level';
    var accent = accentFor(segments);
    if (accent) el.style.setProperty('--orbit-accent', accent);

    el.innerHTML =
      '<div class="orbit__halo" aria-hidden="true"></div>' +
      '<svg class="orbit__ring" aria-hidden="true"><circle cx="50%" cy="50%" r="0"></circle></svg>';

    var center;
    if (here) {
      // The centre field's own page — its articles — one tap away.
      center = document.createElement('a');
      center.className = 'orbit__center';
      center.href = here.href;
      center.textContent = here.title;
      if (here.article_count > 0) {
        var open = document.createElement('span');
        open.className = 'orbit__open';
        open.textContent = 'مشاهده نوشته‌ها';
        center.appendChild(open);
      }
    } else {
      center = document.createElement('p');
      center.className = 'orbit__center';
      center.textContent = rootLabel;
    }
    el.appendChild(center);

    var list = document.createElement('ul');
    list.className = 'orbit__list';
    var items = [];

    nodes.forEach(function (node) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'orbit__node';
      a.href = node.href;
      a.textContent = node.title;
      a.setAttribute('data-slug', node.slug);
      if (node.has_children) a.setAttribute('data-branch', '');
      if (node.accent) a.style.setProperty('--node-accent', node.accent);
      li.appendChild(a);
      list.appendChild(li);
      items.push({ el: a, data: node });
    });

    el.appendChild(list);

    if (!nodes.length) {
      var empty = document.createElement('p');
      empty.className = 'orbit__empty';
      empty.textContent = 'هنوز چیزی در این بخش منتشر نشده است.';
      el.appendChild(empty);
    }

    return { el: el, center: center, nodes: items, mode: 'ring', radius: 0, positions: [] };
  }

  // ============================================================ geometry

  /** A square stage that fits the viewport and the column it sits in. */
  function stageSize() {
    var width = root.clientWidth - 16;
    var height = window.innerHeight * (window.innerWidth < 640 ? 0.62 : 0.7);
    return Math.round(Math.max(280, Math.min(width, height, 760)));
  }

  /**
   * Evenly spaced points on a circle, starting at the top and running
   * counter-clockwise — leftwards first, the way Persian reads. Two names
   * sit left and right of the centre rather than above and below it.
   */
  function pointsOnRing(count, radius, rotation) {
    var step = (Math.PI * 2) / count;
    var start = (count === 2 ? 0 : -Math.PI / 2) - (rotation || 0) * step;
    var points = [];
    for (var i = 0; i < count; i++) {
      var angle = start - i * step;
      points.push({ x: Math.round(Math.cos(angle) * radius), y: Math.round(Math.sin(angle) * radius) });
    }
    return points;
  }

  function overlaps(a, b) {
    return !(a.right + GAP <= b.left || b.right + GAP <= a.left || a.bottom + GAP <= b.top || b.bottom + GAP <= a.top);
  }

  function boxAt(x, y, w, h) {
    return { left: x - w / 2, right: x + w / 2, top: y - h / 2, bottom: y + h / 2 };
  }

  /**
   * Choose ring or list for a built level whose names are already in the
   * DOM (hidden), using their measured sizes.
   */
  function plan(level, size) {
    var n = level.nodes.length;
    level.mode = n === 0 ? 'empty' : 'ring';
    if (!n) return level;

    var half = size / 2;
    var sizes = level.nodes.map(function (item) {
      return { w: item.el.offsetWidth, h: item.el.offsetHeight };
    });
    var centerBox = boxAt(0, 0, level.center.offsetWidth + 16, level.center.offsetHeight + 16);

    // Prefer a ring with some air in it — a tight ring looks cramped even
    // when nothing technically overlaps — but never let that preference
    // rule out a ring that would fit: try the airy radii first, then the
    // tighter ones. Each radius is tried as laid out and rotated half a
    // step, since diagonal slots need less width than side slots.
    var geometricMin = Math.max(centerBox.right, centerBox.bottom) + 12;
    var airyMin = Math.max(geometricMin, size * (n <= 2 ? 0.26 : 0.3));
    var maxRadius = half - 6;
    var radii = [];
    var r;
    for (r = airyMin; r <= maxRadius; r += 6) radii.push(r);
    for (r = geometricMin; r < airyMin; r += 6) radii.push(r);

    for (var k = 0; k < radii.length; k++) {
      for (var rotation = 0; rotation <= 0.5; rotation += 0.5) {
        var points = pointsOnRing(n, radii[k], rotation);
        if (fitsAt(points, sizes, centerBox, half)) {
          level.mode = 'ring';
          level.radius = radii[k];
          level.positions = points;
          return level;
        }
      }
    }

    level.mode = 'list';
    return level;
  }

  function fitsAt(points, sizes, centerBox, half) {
    var boxes = points.map(function (p, i) { return boxAt(p.x, p.y, sizes[i].w, sizes[i].h); });
    for (var i = 0; i < boxes.length; i++) {
      var b = boxes[i];
      if (b.left < -half || b.right > half || b.top < -half || b.bottom > half) return false;
      if (overlaps(b, centerBox)) return false;
      for (var j = i + 1; j < boxes.length; j++) {
        if (overlaps(b, boxes[j])) return false;
      }
    }
    return true;
  }

  function place(level) {
    level.el.setAttribute('data-mode', level.mode);
    var circle = level.el.querySelector('circle');

    if (level.mode === 'ring') {
      circle.setAttribute('r', String(level.radius));
      level.nodes.forEach(function (item, i) {
        item.el.style.transform = transformAt(level.positions[i].x, level.positions[i].y, 1);
      });
    } else {
      circle.setAttribute('r', '0');
      level.nodes.forEach(function (item) { item.el.style.transform = ''; });
    }
  }

  /** The centre word's transform: anchored by its middle in a ring, by flow in a list. */
  function centerAt(level, dx, dy, scale) {
    return level.mode === 'ring'
      ? 'translate(calc(-50% + ' + dx + 'px), calc(-50% + ' + dy + 'px)) scale(' + scale + ')'
      : 'translate(' + dx + 'px, ' + dy + 'px) scale(' + scale + ')';
  }

  function transformAt(x, y, scale) {
    return 'translate(calc(-50% + ' + x + 'px), calc(-50% + ' + y + 'px)) scale(' + scale + ')';
  }

  /** Where an element's centre sits, relative to the stage centre. */
  function offsetFromCenter(el) {
    var s = stage.getBoundingClientRect();
    var r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2 - (s.left + s.width / 2), y: r.top + r.height / 2 - (s.top + s.height / 2), h: r.height };
  }

  // ============================================================ rendering

  /**
   * Show the level at `segments`.
   *
   * direction 'in'  — travelling down; `from` is the name that was chosen
   * direction 'out' — travelling up;   the old centre flies to its slot
   * direction none  — first paint or resize: no animation
   */
  function show(segments, direction, from) {
    var size = stageSize();
    stage.style.setProperty('--stage', size + 'px');

    var next = buildLevel(segments);
    next.el.style.visibility = 'hidden';
    stage.appendChild(next.el);
    plan(next, size);
    place(next);
    next.el.style.visibility = '';

    var previous = current;
    current = next;
    path = segments;

    updateNav();
    announce();

    if (!previous || !direction || reduceMotion) {
      if (previous) previous.el.remove();
      busy = false;
      return Promise.resolve();
    }

    previous.el.setAttribute('data-leaving', '');
    var animations = direction === 'in'
      ? animateIn(previous, next, from)
      : animateOut(previous, next);

    return Promise.all(animations.map(function (a) { return a.finished.catch(function () {}); }))
      .then(function () {
        previous.el.remove();
        busy = false;
        resync();
      });
  }

  function animateIn(previous, next, from) {
    var list = [];

    // The chosen name travels to the centre and grows into the new title.
    if (from && next.center) {
      var start = offsetFromCenter(from);
      var end = offsetFromCenter(next.center);
      var scale = end.h ? Math.min(1, start.h / end.h) : 0.6;
      from.style.opacity = '0';

      list.push(next.center.animate([
        { transform: centerAt(next, start.x - end.x, start.y - end.y, scale) },
        { transform: centerAt(next, 0, 0, 1) }
      ], { duration: 520, easing: EASE_OUT }));
    } else {
      list.push(next.center.animate([{ opacity: 0, transform: centerAt(next, 0, 0, 0.85) }, { opacity: 1, transform: centerAt(next, 0, 0, 1) }], { duration: 360, easing: EASE_OUT }));
    }

    // The old centre sinks away; its siblings drift outward and fade.
    list.push(previous.center.animate([{ opacity: 1 }, { opacity: 0, transform: centerAt(previous, 0, 0, 0.7) }], { duration: 260, easing: EASE, fill: 'forwards' }));
    previous.nodes.forEach(function (item, i) {
      if (item.el === from) return;
      var p = previous.positions[i] || { x: 0, y: 0 };
      list.push(item.el.animate([
        { opacity: 1, transform: transformAt(p.x, p.y, 1) },
        { opacity: 0, transform: transformAt(Math.round(p.x * 1.18), Math.round(p.y * 1.18), 0.92) }
      ], { duration: 260, easing: EASE, fill: 'forwards' }));
    });
    list.push(fade(previous.el.querySelector('.orbit__ring'), 1, 0, 220));

    // The new children fan out from the centre, one after another.
    next.nodes.forEach(function (item, i) {
      if (next.mode !== 'ring') {
        list.push(item.el.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 300, delay: 160 + i * 18, easing: EASE_OUT, fill: 'backwards' }));
        return;
      }
      var p = next.positions[i];
      list.push(item.el.animate([
        { opacity: 0, transform: transformAt(0, 0, 0.4) },
        { opacity: 1, transform: transformAt(p.x, p.y, 1) }
      ], { duration: 460, delay: 150 + i * 34, easing: EASE_OUT, fill: 'backwards' }));
    });
    list.push(grow(next.el.querySelector('.orbit__ring circle'), 140));

    return list;
  }

  function animateOut(previous, next) {
    var list = [];

    // Find the slot in the parent ring that the old centre belongs to.
    var returning = null;
    var cameFrom = previous.slug;
    next.nodes.forEach(function (item) { if (item.data.slug === cameFrom) returning = item; });

    if (returning && next.mode === 'ring') {
      var i = next.nodes.indexOf(returning);
      var p = next.positions[i];
      var start = offsetFromCenter(previous.center);
      list.push(returning.el.animate([
        { transform: transformAt(start.x, start.y, 1.35) },
        { transform: transformAt(p.x, p.y, 1) }
      ], { duration: 500, easing: EASE_OUT }));
      previous.center.style.opacity = '0';
    } else {
      list.push(previous.center.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200, fill: 'forwards' }));
    }

    // The old children fall back into the centre.
    previous.nodes.forEach(function (item, i) {
      var q = previous.positions[i] || { x: 0, y: 0 };
      list.push(item.el.animate([
        { opacity: 1, transform: transformAt(q.x, q.y, 1) },
        { opacity: 0, transform: transformAt(0, 0, 0.4) }
      ], { duration: 300, easing: EASE, fill: 'forwards' }));
    });
    list.push(fade(previous.el.querySelector('.orbit__ring'), 1, 0, 240));

    // The parent's centre and its other children settle back in.
    list.push(next.center.animate([{ opacity: 0, transform: centerAt(next, 0, 0, 1.15) }, { opacity: 1, transform: centerAt(next, 0, 0, 1) }], { duration: 420, delay: 120, easing: EASE_OUT, fill: 'backwards' }));
    next.nodes.forEach(function (item, i) {
      if (item === returning) return;
      if (next.mode !== 'ring') {
        list.push(fade(item.el, 0, 1, 280, 120 + i * 18));
        return;
      }
      var p = next.positions[i];
      list.push(item.el.animate([
        { opacity: 0, transform: transformAt(Math.round(p.x * 1.18), Math.round(p.y * 1.18), 0.92) },
        { opacity: 1, transform: transformAt(p.x, p.y, 1) }
      ], { duration: 420, delay: 140 + i * 26, easing: EASE_OUT, fill: 'backwards' }));
    });
    list.push(grow(next.el.querySelector('.orbit__ring circle'), 100));

    return list;
  }

  function fade(el, from, to, duration, delay) {
    if (!el) return { finished: Promise.resolve() };
    return el.animate([{ opacity: from }, { opacity: to }], { duration: duration, delay: delay || 0, easing: EASE, fill: to === 0 ? 'forwards' : 'backwards' });
  }

  function grow(circle, delay) {
    if (!circle) return { finished: Promise.resolve() };
    return circle.animate([{ transform: 'scale(0.15)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }], { duration: 520, delay: delay, easing: EASE_OUT, fill: 'backwards' });
  }

  // ======================================================= navigation chrome

  function updateNav() {
    var nodes = chain(path);
    nav.hidden = path.length === 0;
    trail.innerHTML = '';

    if (!path.length) return;

    trail.appendChild(crumb(rootLabel, []));
    nodes.forEach(function (node, i) {
      var sep = document.createElement('span');
      sep.setAttribute('aria-hidden', 'true');
      sep.textContent = '/';
      trail.appendChild(sep);
      trail.appendChild(crumb(node.title, path.slice(0, i + 1), i === nodes.length - 1));
    });
  }

  function crumb(label, target, isCurrent) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    if (isCurrent) {
      b.setAttribute('aria-current', 'location');
    } else {
      b.addEventListener('click', function (event) { go(target, 'out', null, event.detail === 0); });
    }
    return b;
  }

  function announce() {
    if (!announcer) return;
    var nodes = chain(path);
    var here = nodes.length ? nodes[nodes.length - 1].title : rootLabel;
    var count = current ? current.nodes.length : 0;
    announcer.textContent = here + '، ' + toPersianDigits(count) + ' زیرشاخه';
  }

  function toPersianDigits(value) {
    return String(value).replace(/\d/g, function (d) { return String.fromCharCode(0x06F0 + Number(d)); });
  }

  // ============================================================ navigation

  /**
   * viaKeyboard: focus follows the change only when the reader is using the
   * keyboard. After a tap, moving focus makes the first name look selected.
   */
  function go(segments, direction, from, viaKeyboard) {
    if (busy) return;
    busy = !reduceMotion;

    var previousSlug = path[path.length - 1];
    var hash = segments.length ? '#/' + segments.map(encodeURIComponent).join('/') : '#/';
    if (window.location.hash !== hash) {
      window.history.pushState({ orbit: segments }, '', hash);
    }

    if (current) current.slug = previousSlug;

    show(segments, direction, from).then(function () {
      if (viaKeyboard) focusAfter(direction, previousSlug);
    });
  }

  function focusAfter(direction, previousSlug) {
    if (!current) return;
    var target = null;
    if (direction === 'out' && previousSlug) {
      current.nodes.forEach(function (item) { if (item.data.slug === previousSlug) target = item.el; });
    }
    if (!target && current.nodes.length) target = current.nodes[0].el;
    if (target) target.focus({ preventScroll: true });
  }

  function segmentsFromHash() {
    var raw = window.location.hash.replace(/^#\/?/, '');
    if (!raw) return [];
    var segments = raw.split('/').filter(Boolean).map(function (s) {
      try { return decodeURIComponent(s); } catch (e) { return s; }
    });
    // A hash pointing at something that no longer exists falls back to the top.
    return childrenOf(segments) === null ? [] : segments;
  }

  // ================================================================ events

  stage.addEventListener('click', function (event) {
    var link = event.target.closest('.orbit__node');
    if (!link || event.defaultPrevented) return;

    // New tab, new window, download: always the plain link.
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    if (link.hasAttribute('data-branch')) {
      event.preventDefault();
      go(path.concat([link.getAttribute('data-slug')]), 'in', link, event.detail === 0);
      return;
    }

    // A leaf opens its page, with a brief zoom so the change of place reads.
    if (reduceMotion) return;
    event.preventDefault();
    var href = link.href;
    link.animate([{ transform: link.style.transform }, { transform: link.style.transform.replace(/scale\([^)]*\)/, 'scale(1.18)'), opacity: 0.2 }], { duration: 200, easing: EASE, fill: 'forwards' });
    current.el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 220, easing: EASE, fill: 'forwards' })
      .finished.then(function () { window.location.assign(href); });
  });

  upButton.addEventListener('click', function (event) {
    if (path.length) go(path.slice(0, -1), 'out', null, event.detail === 0);
  });

  stage.addEventListener('keydown', function (event) {
    if (!current || !current.nodes.length) return;
    var items = current.nodes.map(function (item) { return item.el; });
    var index = items.indexOf(document.activeElement);

    // Around the ring in reading order. In RTL "next" is to the left.
    var step = { ArrowLeft: 1, ArrowDown: 1, ArrowRight: -1, ArrowUp: -1 }[event.key];
    if (step !== undefined && index !== -1) {
      event.preventDefault();
      items[(index + step + items.length) % items.length].focus();
    } else if (event.key === 'Home' && index !== -1) {
      event.preventDefault();
      items[0].focus();
    } else if (event.key === 'End' && index !== -1) {
      event.preventDefault();
      items[items.length - 1].focus();
    }
  });

  document.addEventListener('keydown', function (event) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement && document.activeElement.tagName);
    if (event.key === 'Escape' && path.length && !typing) {
      go(path.slice(0, -1), 'out', null, true);
    }
  });

  window.addEventListener('popstate', function () {
    // Mid-animation, remember that the URL moved and catch up afterwards;
    // ignoring it left the orbit showing a different place than the address
    // bar (a back-press during the fan-out did exactly that).
    if (busy) {
      resyncWhenIdle = true;
      return;
    }
    followHash();
  });

  function followHash() {
    var target = segmentsFromHash();
    if (target.join('/') === path.join('/')) return;
    var direction = target.length < path.length ? 'out' : 'in';
    if (current) current.slug = path[path.length - 1];
    busy = !reduceMotion;
    show(target, direction, null);
  }

  function resync() {
    if (!resyncWhenIdle) return;
    resyncWhenIdle = false;
    followHash();
  }

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      if (!busy) show(path, null, null);
    }, 160);
  });

  // ================================================================= start

  stage.classList.add('is-live');
  stage.innerHTML = '';
  show(segmentsFromHash(), null, null);
})();
