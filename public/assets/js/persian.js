/**
 * Client-side mirror of App\Support\PersianText.
 *
 * The two implementations must agree exactly, or the instant-search box will
 * suggest things the server then cannot find. tools/tests/fixtures/persian.json
 * is run against both by the test suite to keep them honest.
 */
(function (global) {
  'use strict';

  var ZWNJ = '‌';

  var INVISIBLES = /[​‍‎‏‪-‮⁦-⁩﻿ـ]/g;
  var DIACRITICS = /[ً-ٰٟۖ-ۭ]/g;

  var CHAR_FOLD = {
    'ك': 'ک', 'ڪ': 'ک',
    'ي': 'ی', 'ى': 'ی', 'ے': 'ی',
    'ۀ': 'ه', 'ہ': 'ه', 'ە': 'ه',
    'ٵ': 'ا', 'ٶ': 'و', 'ٷ': 'و', 'ٸ': 'ی'
  };

  var INDEX_FOLD = {
    'آ': 'ا', 'أ': 'ا', 'إ': 'ا', 'ٱ': 'ا',
    'ة': 'ه', 'ؤ': 'و', 'ئ': 'ی', 'ء': ''
  };

  var PERSIAN_DIGITS = /[۰-۹]/g;
  var ARABIC_DIGITS = /[٠-٩]/g;

  function fold(text, table) {
    var out = '';
    for (var i = 0; i < text.length; i++) {
      var ch = text.charAt(i);
      out += Object.prototype.hasOwnProperty.call(table, ch) ? table[ch] : ch;
    }
    return out;
  }

  function toAsciiDigits(text) {
    return text
      .replace(PERSIAN_DIGITS, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
      .replace(ARABIC_DIGITS, function (d) { return String(d.charCodeAt(0) - 0x0660); });
  }

  // Unicode property escapes need ES2018. Every browser that supports service
  // workers supports them, but the fallback keeps an old phone functional
  // rather than throwing at parse time.
  var SEPARATORS;
  try {
    SEPARATORS = new RegExp('[^\\p{L}\\p{M}\\p{N}_]+', 'gu');
  } catch (e) {
    SEPARATORS = /[^a-zA-Z0-9_؀-ۿ]+/g;
  }

  var TOKEN_SEPARATORS;
  try {
    TOKEN_SEPARATORS = new RegExp('[^\\p{L}\\p{M}\\p{N}_' + ZWNJ + ']+', 'gu');
  } catch (e) {
    TOKEN_SEPARATORS = new RegExp('[^a-zA-Z0-9_؀-ۿ' + ZWNJ + ']+', 'g');
  }

  function normalize(text) {
    if (text === null || text === undefined) return '';

    var out = String(text).replace(INVISIBLES, '');
    out = fold(out, CHAR_FOLD);
    out = fold(out, INDEX_FOLD);
    out = out.replace(DIACRITICS, '');
    out = toAsciiDigits(out);
    out = out.split(ZWNJ).join(' ');
    out = out.toLowerCase();
    out = out.replace(SEPARATORS, ' ');

    return out.replace(/\s+/g, ' ').trim();
  }

  /**
   * Words containing a half-space are emitted both joined and split, so a
   * reader finds an article whether or not they type the ZWNJ.
   */
  function tokenize(text, minLength) {
    minLength = minLength || 2;

    var out = String(text === null || text === undefined ? '' : text).replace(INVISIBLES, '');
    out = fold(out, CHAR_FOLD);
    out = fold(out, INDEX_FOLD);
    out = out.replace(DIACRITICS, '');
    out = toAsciiDigits(out).toLowerCase();
    out = out.replace(TOKEN_SEPARATORS, ' ');

    var words = out.split(/\s+/).filter(Boolean);
    var tokens = [];

    for (var i = 0; i < words.length; i++) {
      var word = words[i];
      if (word.indexOf(ZWNJ) === -1) {
        tokens.push(word);
        continue;
      }
      tokens.push(word.split(ZWNJ).join(''));
      var parts = word.split(ZWNJ);
      for (var j = 0; j < parts.length; j++) {
        if (parts[j]) tokens.push(parts[j]);
      }
    }

    var seen = Object.create(null);
    var keep = [];
    for (var k = 0; k < tokens.length; k++) {
      var token = tokens[k];
      if (seen[token]) continue;
      if (token.length >= minLength || /^\d+$/.test(token)) {
        seen[token] = true;
        keep.push(token);
      }
    }
    return keep;
  }

  function toPersianDigits(value) {
    return String(value).replace(/\d/g, function (d) {
      return String.fromCharCode(0x06F0 + Number(d));
    });
  }

  global.PersianText = {
    ZWNJ: ZWNJ,
    normalize: normalize,
    tokenize: tokenize,
    toAsciiDigits: toAsciiDigits,
    toPersianDigits: toPersianDigits
  };
})(typeof window !== 'undefined' ? window : globalThis);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : globalThis).PersianText;
}
