/**
 * Article page behaviour: contents scroll-spy, the serving scaler, and
 * checkable steps.
 *
 * Everything here is per-device and stays on the device. Nothing is sent to
 * the server and no cookie is ever set — localStorage is used for the two
 * conveniences a reader would otherwise lose on a refresh, and every access is
 * wrapped because it throws in private mode and when site data is blocked.
 */
(function () {
  'use strict';

  var store = {
    get: function (key) {
      try { return window.localStorage.getItem(key); } catch (e) { return null; }
    },
    set: function (key, value) {
      try { window.localStorage.setItem(key, value); } catch (e) { /* nothing to do */ }
    },
    remove: function (key) {
      try { window.localStorage.removeItem(key); } catch (e) { /* nothing to do */ }
    }
  };

  // ----------------------------------------------------- contents scroll-spy

  var toc = document.querySelector('[data-toc]');
  if (toc) {
    var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
    var headings = links
      .map(function (link) {
        var id = decodeURIComponent(link.getAttribute('href').slice(1));
        return document.getElementById(id);
      })
      .filter(Boolean);

    if (headings.length && 'IntersectionObserver' in window) {
      var visible = new Set();

      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) visible.add(entry.target);
          else visible.delete(entry.target);
        });

        // Highlight the topmost heading currently on screen; if none is, keep
        // the last one scrolled past so the contents never goes blank.
        var active = null;
        for (var i = 0; i < headings.length; i++) {
          if (visible.has(headings[i])) { active = headings[i]; break; }
        }
        if (!active) {
          for (var j = headings.length - 1; j >= 0; j--) {
            if (headings[j].getBoundingClientRect().top < 120) { active = headings[j]; break; }
          }
        }

        links.forEach(function (link) {
          var id = decodeURIComponent(link.getAttribute('href').slice(1));
          if (active && id === active.id) link.setAttribute('aria-current', 'true');
          else link.removeAttribute('aria-current');
        });
      }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });

      headings.forEach(function (heading) { observer.observe(heading); });
    }

    var toggle = document.querySelector('[data-toc-toggle]');
    if (toggle) {
      // Collapsed by default on phones, expanded on wide screens where the
      // sidebar is always visible.
      var wide = window.matchMedia('(min-width: 60rem)');
      toc.hidden = !wide.matches;
      toggle.setAttribute('aria-expanded', String(wide.matches));

      toggle.addEventListener('click', function () {
        toc.hidden = !toc.hidden;
        toggle.setAttribute('aria-expanded', String(!toc.hidden));
      });
    }
  }

  // ------------------------------------------------------- serving scaler

  var scaler = document.querySelector('[data-scaler]');
  if (scaler) {
    var amounts = Array.prototype.slice.call(document.querySelectorAll('[data-base-amount]'));
    var yieldOut = document.querySelector('[data-base-yield]');
    var buttons = Array.prototype.slice.call(scaler.querySelectorAll('button[data-factor]'));

    var applyFactor = function (factor) {
      amounts.forEach(function (node) {
        var base = parseFloat(node.getAttribute('data-base-amount'));
        if (isNaN(base)) return;
        node.textContent = formatAmount(base * factor);
      });

      if (yieldOut) {
        var baseYield = parseFloat(yieldOut.getAttribute('data-base-yield'));
        if (!isNaN(baseYield)) {
          yieldOut.textContent = formatAmount(baseYield * factor);
        }
      }

      buttons.forEach(function (button) {
        button.setAttribute('aria-pressed', String(parseFloat(button.getAttribute('data-factor')) === factor));
      });
    };

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        applyFactor(parseFloat(button.getAttribute('data-factor')));
      });
    });

    applyFactor(1);
  }

  /**
   * Quantities are rendered as Persian digits, with common fractions kept as
   * fractions: "نصف پیمانه" reads far better than "۰٫۵ پیمانه".
   */
  function formatAmount(value) {
    var rounded = Math.round(value * 100) / 100;
    var whole = Math.floor(rounded);
    var fraction = rounded - whole;

    var glyph = '';
    if (Math.abs(fraction - 0.25) < 0.02) glyph = '¼';
    else if (Math.abs(fraction - 0.5) < 0.02) glyph = '½';
    else if (Math.abs(fraction - 0.75) < 0.02) glyph = '¾';
    else if (Math.abs(fraction - 0.33) < 0.03) glyph = '⅓';
    else if (Math.abs(fraction - 0.67) < 0.03) glyph = '⅔';

    if (glyph) {
      return (whole > 0 ? toPersian(whole) + ' ' : '') + glyph;
    }
    return toPersian(rounded % 1 === 0 ? String(whole) : rounded.toFixed(1).replace('.', '٫'));
  }

  function toPersian(value) {
    return String(value).replace(/\d/g, function (d) {
      return String.fromCharCode(0x06F0 + Number(d));
    });
  }

  // ---------------------------------------------------------------- steps

  var steps = document.querySelector('[data-steps]');
  if (steps) {
    var key = 'steps:' + (steps.getAttribute('data-article') || location.pathname);
    var boxes = Array.prototype.slice.call(steps.querySelectorAll('input[type="checkbox"]'));

    var saved = store.get(key);
    if (saved) {
      saved.split(',').forEach(function (index) {
        var box = boxes[Number(index)];
        if (box) box.checked = true;
      });
    }

    var persist = function () {
      var checked = [];
      boxes.forEach(function (box, index) { if (box.checked) checked.push(index); });
      if (checked.length) store.set(key, checked.join(','));
      else store.remove(key);
    };

    boxes.forEach(function (box) { box.addEventListener('change', persist); });

    var reset = document.querySelector('[data-steps-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        boxes.forEach(function (box) { box.checked = false; });
        store.remove(key);
      });
    }
  }
})();
