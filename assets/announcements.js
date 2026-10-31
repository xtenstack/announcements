// Served by Announcements\Controllers\AssetsController at
// /announcements/assets/announcements.js (see Module::registerRoutes()) —
// the same file-served-by-an-action pattern ai-ssa-chat uses for its
// widget.js, since app_skeleton has no mechanism for a module to publish
// static files under /assets/. Loaded by this module's layout
// (views/index.phtml) after the host's app.js.
//
// The host CSP is script-src 'self' with no 'unsafe-inline' (see
// app_skeleton docker/Caddyfile), which silently blocks inline event
// handler attributes — an onclick="return confirm(...)" never runs, so
// the click goes straight through. This replaces those with a
// data-confirm attribute handled here, delegated on document so it
// covers every button on every page of this module without per-page
// wiring. Without JS the buttons still work; they just don't ask first.
(function () {
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-confirm]');
    if (!el) return;
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);
})();
