/**
 * Applies the saved theme before first paint, so a reader who chose dark mode
 * never sees a white flash.
 *
 * This is a separate file rather than an inline <script> because the site's
 * Content-Security-Policy is 'self'-only with no inline scripts — the same
 * policy that guarantees nothing foreign has to load for a page to render.
 * It must stay tiny and must stay in <head> before the stylesheet.
 */
(function () {
  try {
    var theme = window.localStorage.getItem('theme');
    if (theme === 'dark' || theme === 'light') {
      document.documentElement.setAttribute('data-theme', theme);
    }
  } catch (e) {
    // Private mode or blocked site data: fall back to the system preference,
    // which the stylesheet already handles.
  }
})();
