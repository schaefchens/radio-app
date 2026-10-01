// Park an early install event for `?install=1` (src/lib/pwaInstall.ts adopts
// it). `beforeinstallprompt` can fire before the bundle runs at all: module
// scripts are deferred, so on a warm visit Chrome can decide the app is
// installable while the document is still parsing, and nothing re-fires the
// event on request — the link would fall through to written instructions on
// the one browser that has a real dialog. A file and not an inline <script>:
// the CSP allows no inline script.
//
// Only when the link asked for it: preventDefault() also suppresses Chrome's
// own mini-infobar, which every other visitor keeps. The latch key is the one
// in pwaInstall.ts (a reload between arrival and answer).
(function () {
  var asked = /[?&]install=1(&|$)/.test(location.search);
  try {
    asked = asked || sessionStorage.getItem('arche.install.intent') === '1';
  } catch (e) {
    // Storage blocked: the URL was still read.
  }
  if (!asked) return;
  addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.__installPromptEvent = e;
  });
})();
