/// <reference types="@capacitor/splash-screen" />
/// <reference types="@capacitor/local-notifications" />
import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The store apps are a frame around the live site (CLAUDE.md, "Native apps"):
 * the same origin as every browser, so YouTube gets its Referer (an app
 * loading its own copy from capacitor://localhost gets error 153), and the
 * API, the CDN, the clock and the chat work as they do on the web.
 */
const LIVE = 'https://radio.schaefchens.de';

const config: CapacitorConfig = {
  appId: 'de.schaefchens.apps.archeradio',
  appName: 'Arche Radio',
  // Only the page for "the station cannot be reached" — which is also the
  // index.html iOS refuses to start without.
  webDir: 'native/www',
  // Behind the splash and under the first paint: the PWA manifest's navy.
  backgroundColor: '#03234a',
  server: {
    // The exact origin: no path, no trailing slash. Android injects the bridge
    // only into pages of this origin and builds its bridge's origin rule from
    // it (a malformed rule falls back to a bridge every frame can reach,
    // YouTube's iframe included); iOS treats as the app only URLs that start
    // with this string. A redirect to another host would load the site
    // without its plugins.
    url: LIVE,
    // Offline at a cold start, DNS, and on Android an HTTP error of the page.
    // Android serves it from https://localhost, without the plugins.
    errorPath: 'index.html',
    // No allowNavigation: iframes stay inside, everything else a page links
    // to opens in the system browser or its app.
  },
  ios: {
    // The page draws its own safe areas (--safe-*, index.css).
    contentInset: 'never',
    // A long press is a press, as in the installed PWA.
    allowsLinkPreview: false,
    // scrollEnabled stays on: the document scrolls (the dock reads window.scrollY).
  },
  android: {
    allowMixedContent: false,
    // Vite builds for Chrome 111: older WebViews get the error page, which says why.
    minWebViewVersion: 111,
    // resolveServiceWorkerRequests stays on: the site's service worker keeps working.
  },
  plugins: {
    // Both off by default; stated so nobody turns them on. The page talks to
    // its own origin with fetch, and patched cookies would serve no one.
    CapacitorHttp: { enabled: false },
    CapacitorCookies: { enabled: false },
    SplashScreen: {
      // Never stuck: hidden after 3 s even by a page that cannot hide it
      // (Android's error page has no plugins; an old cached bundle predates
      // the call). App.tsx hides it as soon as React has rendered.
      launchAutoHide: true,
      launchShowDuration: 3000,
      launchFadeOutDuration: 200,
      backgroundColor: '#03234a',
      showSpinner: false,
      androidScaleType: 'CENTER_CROP',
    },
    SystemBars: {
      // --safe-area-inset-* for the page (Android WebView < 140 pads the page
      // and reports zero; < 144 needs the keyboard's height from here).
      insetsHandling: 'css',
      // index.html has viewport-fit=cover; saying so avoids a layout jump.
      initialViewportFitValueHint: 'cover',
      // Light icons over the navy splash; theme.ts takes over.
      style: 'DARK',
    },
    LocalNotifications: {
      smallIcon: 'ic_stat_arche',
      iconColor: '#dda12b',
    },
  },
};

export default config;
