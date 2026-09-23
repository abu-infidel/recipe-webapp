/**
 * The structured article editor.
 *
 * Used by the owner's admin editor and by the contributor form, which pass
 * different labels. It reads everything from a JSON island:
 *
 *   <script type="application/json" id="composer-data">
 *     {doc, media: {id: {url}}, upload: "/…/media", strings: {…}}
 *   </script>
 *
 * renders the body, recipe and references into [data-composer], and on
 * submit writes the whole document as JSON into [data-composer-doc]. The
 * server re-validates all of it — this script is a convenience, not a gate.
 *
 * No framework and no build step: state lives in one object, structural
 * changes re-render, and typing updates the state in place so focus is kept.
 */
(function () {
  'use strict';

  var island = document.getElementById('composer-data');
  var root = document.querySelector('[data-composer]');
  var form = document.querySelector('[data-composer-form]');
  if (!island || !root || !form) return;

  var data;
  try {
    data = JSON.parse(island.textContent || '{}');
  } catch (e) {
    root.textContent = 'The editor could not start.';
    return;
  }

  var S = data.strings || {};
  var media = data.media || {};
  var doc = data.doc || {};
  doc.intro = doc.intro || [];
  doc.sections = doc.sections || [];
  doc.references = doc.references || [];
  var savedRecipe = doc.recipe || null;
  var kindSelect = document.querySelector('[data-composer-kind]');
  var dirty = false;

  var BLOCK_TYPES = ['paragraph', 'subheading', 'list', 'ordered', 'tip', 'note', 'warning', 'quote', 'image'];

  // ------------------------------------------------------------- helpers

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) return;
      if (key === 'on') {
        Object.keys(value).forEach(function (event) { el.addEventListener(event, value[event]); });
      } else if (key === 'text') {
        el.textContent = value;
      } else if (key === 'value') {
        el.value = value;
      } else {
        el.setAttribute(key, value === true ? '' : String(value));
      }
    });
    (children || []).forEach(function (child) {
      if (child) el.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return el;
  }

  function label(key) { return S[key] || key; }

  function textInput(value, onInput, attrs) {
    return h('input', Object.assign({
      type: 'text', value: value == null ? '' : value, dir: S.dir || 'auto', 'class': 'fa',
      on: { input: function (e) { onInput(e.target.value); dirty = true; } }
    }, attrs || {}));
  }

  function textArea(value, onInput, attrs) {
    var el = h('textarea', Object.assign({
      dir: S.dir || 'auto', 'class': 'fa composer-text', rows: 3,
      on: { input: function (e) { onInput(e.target.value); dirty = true; grow(e.target); } }
    }, attrs || {}));
    el.value = value || '';
    requestAnimationFrame(function () { grow(el); });
    return el;
  }

  function grow(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight + 2, 600) + 'px';
  }

  function button(text, onClick, attrs) {
    return h('button', Object.assign({ type: 'button', 'class': 'btn btn--sm', on: { click: onClick } }, attrs || {}), [text]);
  }

  function move(list, index, delta) {
    var target = index + delta;
    if (target < 0 || target >= list.length) return;
    var item = list.splice(index, 1)[0];
    list.splice(target, 0, item);
    dirty = true;
    render();
  }

  function remove(list, index) {
    list.splice(index, 1);
    dirty = true;
    render();
  }

  function controls(list, index) {
    return h('span', { 'class': 'composer-controls' }, [
      button('↑', function () { move(list, index, -1); }, { 'aria-label': label('up'), title: label('up'), disabled: index === 0 }),
      button('↓', function () { move(list, index, 1); }, { 'aria-label': label('down'), title: label('down'), disabled: index === list.length - 1 }),
      button('×', function () { remove(list, index); }, { 'aria-label': label('remove'), title: label('remove'), 'class': 'btn btn--sm btn--danger' })
    ]);
  }

  function csrf() {
    var input = form.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  }

  /** Upload one image; resolves with {id, url, width, height}. */
  function upload(file, extra) {
    var body = new FormData();
    body.append('image', file);
    body.append('_csrf', csrf());
    Object.keys(extra || {}).forEach(function (key) { body.append(key, extra[key]); });

    return fetch(data.upload, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().catch(function () { return { ok: false, error: response.status + '' }; });
      })
      .then(function (result) {
        if (!result.ok) throw new Error(result.error || label('upload_failed'));
        media[result.id] = { url: result.url, width: result.width, height: result.height };
        return result;
      });
  }

  // -------------------------------------------------------------- blocks

  function blockEditor(list, index) {
    var block = list[index];

    var typeSelect = h('select', {
      'aria-label': label('type'),
      on: { change: function (e) { block.type = e.target.value; dirty = true; render(); } }
    }, BLOCK_TYPES.map(function (type) {
      return h('option', { value: type, selected: block.type === type }, [(S.types || {})[type] || type]);
    }));

    var body;
    if (block.type === 'image') {
      body = imageBlock(block);
    } else if (block.type === 'subheading') {
      body = textInput(block.text, function (v) { block.text = v; }, { 'aria-label': (S.types || {}).subheading || 'Subheading' });
    } else {
      body = textArea(block.text, function (v) { block.text = v; }, {
        placeholder: (S.placeholders || {})[block.type] || (S.placeholders || {}).paragraph || '',
        'aria-label': (S.types || {})[block.type] || block.type,
        rows: block.type === 'paragraph' ? 4 : 3
      });
    }

    return h('div', { 'class': 'composer-block composer-block--' + block.type }, [
      h('div', { 'class': 'composer-block__bar' }, [typeSelect, controls(list, index)]),
      body
    ]);
  }

  function imageBlock(block) {
    var item = block.media_id ? media[block.media_id] : null;
    var status = h('span', { 'class': 'hint' });
    var preview = item ? h('img', { src: item.url, alt: '', 'class': 'composer-thumb' }) : null;
    var file = h('input', {
      type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif',
      'aria-label': item ? label('replace') : label('upload'),
      on: {
        change: function (e) {
          if (!e.target.files || !e.target.files[0]) return;
          status.textContent = label('uploading');
          upload(e.target.files[0], { kind: 'inline', caption: block.text || '' })
            .then(function (result) { block.media_id = result.id; dirty = true; render(); })
            .catch(function (error) { status.textContent = label('upload_failed') + ': ' + error.message; });
        }
      }
    });

    return h('div', { 'class': 'composer-image' }, [
      preview,
      file,
      status,
      textInput(block.text, function (v) { block.text = v; }, { placeholder: (S.placeholders || {}).image || '', 'aria-label': (S.placeholders || {}).image || 'Caption' })
    ]);
  }

  function blockList(list, key) {
    var wrap = h('div', { 'class': 'composer-blocks', 'data-list': key }, list.map(function (_, i) { return blockEditor(list, i); }));

    var chooser = h('select', { 'aria-label': label('add_block') }, BLOCK_TYPES.map(function (type) {
      return h('option', { value: type }, [(S.types || {})[type] || type]);
    }));
    wrap.appendChild(h('div', { 'class': 'composer-add' }, [
      chooser,
      button('+ ' + label('add_block'), function () {
        list.push({ type: chooser.value, text: '', media_id: null });
        dirty = true;
        render();
        focusLast('[data-list="' + key + '"] .composer-block');
      })
    ]));

    return wrap;
  }

  // ------------------------------------------------------------- recipe

  function recipeEditor() {
    var r = doc.recipe;

    function num(key, labelKey) {
      return h('div', { 'class': 'field' }, [
        h('label', { text: label(labelKey) }),
        textInput(r[key] == null ? '' : r[key], function (v) { r[key] = v; }, { inputmode: 'numeric', dir: 'auto' })
      ]);
    }

    var ingredients = h('div', { 'class': 'composer-rows' }, r.ingredients.map(function (row, i) {
      return h('div', { 'class': 'composer-row composer-row--ingredient' }, [
        textInput(row.quantity == null ? '' : String(row.quantity), function (v) { row.quantity = v; }, { placeholder: label('quantity'), 'aria-label': label('quantity') }),
        textInput(row.unit, function (v) { row.unit = v; }, { placeholder: label('unit'), 'aria-label': label('unit') }),
        textInput(row.name, function (v) { row.name = v; }, { placeholder: label('name'), 'aria-label': label('name') }),
        textInput(row.note, function (v) { row.note = v; }, { placeholder: label('note'), 'aria-label': label('note') }),
        controls(r.ingredients, i)
      ]);
    }));

    var steps = h('ol', { 'class': 'composer-rows composer-steps' }, r.steps.map(function (row, i) {
      return h('li', { 'class': 'composer-row' }, [
        textArea(row.text, function (v) { row.text = v; }, { rows: 2, 'aria-label': label('step') + ' ' + (i + 1) }),
        controls(r.steps, i)
      ]);
    }));

    return h('div', { 'class': 'card composer-recipe' }, [
      h('p', { 'class': 'card__title', text: label('recipe') }),
      h('div', { 'class': 'field-row' }, [
        num('yield_number', 'yield'),
        h('div', { 'class': 'field' }, [h('label', { text: label('yield_unit') }), textInput(r.yield_unit, function (v) { r.yield_unit = v; })]),
        num('prep_minutes', 'prep'),
        num('cook_minutes', 'cook'),
        h('div', { 'class': 'field' }, [h('label', { text: label('difficulty') }), textInput(r.difficulty, function (v) { r.difficulty = v; })])
      ]),
      h('h3', { 'class': 'composer-subtitle', text: label('ingredients') }),
      ingredients,
      button('+ ' + label('add_ingredient'), function () {
        r.ingredients.push({ quantity: '', unit: '', name: '', note: '' });
        dirty = true;
        render();
        focusLast('.composer-row--ingredient');
      }),
      h('h3', { 'class': 'composer-subtitle', text: label('steps') }),
      steps,
      button('+ ' + label('add_step'), function () {
        r.steps.push({ text: '' });
        dirty = true;
        render();
        focusLast('.composer-steps > li');
      })
    ]);
  }

  // ---------------------------------------------------------- references

  function referencesEditor() {
    var rows = h('ol', { 'class': 'composer-rows composer-references' }, doc.references.map(function (ref, i) {
      return h('li', { 'class': 'composer-reference' }, [
        h('div', { 'class': 'composer-reference__head' }, [
          h('strong', { text: '[' + (i + 1) + ']' }),
          controls(doc.references, i)
        ]),
        h('div', { 'class': 'field-row' }, [
          textInput(ref.url, function (v) { ref.url = v; }, { placeholder: label('url'), 'aria-label': label('url'), dir: 'ltr', 'class': '', type: 'url' }),
          textInput(ref.title, function (v) { ref.title = v; }, { placeholder: label('title'), 'aria-label': label('title'), dir: 'auto' })
        ]),
        h('div', { 'class': 'field-row' }, [
          textInput(ref.author, function (v) { ref.author = v; }, { placeholder: label('author'), 'aria-label': label('author'), dir: 'auto' }),
          textInput(ref.published_date, function (v) { ref.published_date = v; }, { placeholder: label('date'), 'aria-label': label('date'), dir: 'ltr', 'class': '' })
        ]),
        textArea(ref.quote, function (v) { ref.quote = v; }, { rows: 2, placeholder: label('quote'), 'aria-label': label('quote'), dir: 'auto' })
      ]);
    }));

    return h('div', { 'class': 'card' }, [
      h('p', { 'class': 'card__title', text: label('references') }),
      h('p', { 'class': 'hint', text: label('reference_hint') }),
      rows,
      button('+ ' + label('add_reference'), function () {
        doc.references.push({ url: '', title: '', author: '', published_date: '', quote: '' });
        dirty = true;
        render();
        focusLast('.composer-reference');
      })
    ]);
  }

  // -------------------------------------------------------------- render

  function isRecipe() {
    return kindSelect ? kindSelect.value === 'recipe' : !!doc.recipe;
  }

  /** Focus the first text field of the last element matching selector. */
  function focusLast(selector) {
    var matches = root.querySelectorAll(selector);
    var last = matches[matches.length - 1];
    var input = last && last.querySelector('textarea, input[type=text], input[type=url]');
    if (input) input.focus();
  }

  /** Re-render everything from the state. */
  function render() {
    if (isRecipe() && !doc.recipe) {
      doc.recipe = savedRecipe || { yield_number: null, yield_unit: 'نفر', prep_minutes: null, cook_minutes: null, difficulty: '', ingredients: [], steps: [] };
    }

    var parts = [];

    if (isRecipe()) parts.push(recipeEditor());

    parts.push(h('div', { 'class': 'card' }, [
      h('p', { 'class': 'card__title', text: label('intro') }),
      blockList(doc.intro, 'intro')
    ]));

    doc.sections.forEach(function (section, i) {
      parts.push(h('div', { 'class': 'card composer-section' }, [
        h('div', { 'class': 'composer-section__head' }, [
          h('span', { 'class': 'card__title', text: label('section') + ' ' + (i + 1) }),
          controls(doc.sections, i)
        ]),
        h('div', { 'class': 'field' }, [
          textInput(section.heading, function (v) { section.heading = v; }, { placeholder: label('heading'), 'aria-label': label('heading') + ' ' + (i + 1), 'class': 'fa composer-heading' })
        ]),
        blockList(section.blocks, 's' + i)
      ]));
    });

    parts.push(h('div', { 'class': 'composer-add-section' }, [
      button('+ ' + label('add_section'), function () {
        doc.sections.push({ heading: '', blocks: [{ type: 'paragraph', text: '', media_id: null }] });
        dirty = true;
        render();
        focusLast('.composer-section');
      })
    ]));

    parts.push(referencesEditor());

    // replaceChildren() is missing from older Android WebViews.
    while (root.firstChild) root.removeChild(root.firstChild);
    parts.forEach(function (part) { root.appendChild(part); });
  }

  if (kindSelect) {
    kindSelect.addEventListener('change', function () {
      if (!isRecipe() && doc.recipe) {
        savedRecipe = doc.recipe;   // kept in case the kind is switched back
        doc.recipe = null;
      }
      dirty = true;
      render();
    });
  }

  // ------------------------------------------------------------ the hero

  var hero = document.querySelector('[data-composer-hero]');
  if (hero) {
    var heroId = hero.querySelector('[data-hero-id]');
    var heroPreview = hero.querySelector('[data-hero-preview]');
    var heroStatus = hero.querySelector('[data-hero-status]');
    var heroRemove = hero.querySelector('[data-hero-remove]');

    hero.querySelector('[data-hero-file]').addEventListener('change', function (e) {
      if (!e.target.files || !e.target.files[0]) return;
      heroStatus.textContent = label('uploading');
      upload(e.target.files[0], {
        kind: 'hero',
        alt: hero.querySelector('[data-hero-alt]').value,
        ai_generated: hero.querySelector('[data-hero-ai]').checked ? '1' : '0'
      }).then(function (result) {
        heroId.value = result.id;
        heroPreview.src = result.url;
        heroPreview.hidden = false;
        heroRemove.hidden = false;
        heroStatus.textContent = '';
        dirty = true;
      }).catch(function (error) {
        heroStatus.textContent = label('upload_failed') + ': ' + error.message;
      });
    });

    heroRemove.addEventListener('click', function () {
      heroId.value = '';
      heroPreview.hidden = true;
      heroRemove.hidden = true;
      dirty = true;
    });
  }

  // -------------------------------------------------------------- submit

  form.addEventListener('submit', function () {
    form.querySelector('[data-composer-doc]').value = JSON.stringify({
      format: doc.format || 'composer/1',
      intro: doc.intro,
      sections: doc.sections,
      references: doc.references,
      recipe: isRecipe() ? doc.recipe : null
    });
    dirty = false;
  });

  form.addEventListener('input', function () { dirty = true; });

  window.addEventListener('beforeunload', function (e) {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  render();
})();
