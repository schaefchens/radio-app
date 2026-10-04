import { describe, expect, it } from 'vitest';
import { entryOf } from '@/lib/pwaUpdate';

/**
 * Without a service worker (the iOS app) an update is a new entry script in
 * index.html. A pattern that silently found nothing would hide every update.
 */
const BUILT = `<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <title>Arche Radio</title>
    <!-- Not deferred: it must listen before the browser decides the app is installable (lib/pwaInstall.ts). -->
    <script src="/install-event.js"></script>
    <script type="module" crossorigin src="/assets/index-CBV2YVDT.js"></script>
    <link rel="modulepreload" crossorigin href="/assets/rolldown-runtime-CbXtAM7H.js">
    <link rel="stylesheet" crossorigin href="/assets/index-Dj2riAdL.css">
  <link rel="manifest" href="/manifest.webmanifest"></head>
  <body><div id="root"></div></body>
</html>`;

describe("the page's entry script", () => {
  it('is the module script, not install-event.js', () => {
    expect(entryOf(BUILT)).toBe('/assets/index-CBV2YVDT.js');
  });

  it('whatever the order of its attributes', () => {
    expect(entryOf(`<script src='/assets/index-a.js' crossorigin type='module'></script>`)).toBe('/assets/index-a.js');
  });

  it('nothing in a page that is not ours (an error page)', () => {
    expect(entryOf('<html><body>502 Bad Gateway</body></html>')).toBeNull();
  });
});
