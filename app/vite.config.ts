import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'node:path';
import { execSync } from 'node:child_process';

/**
 * The PWA is served from the web root of the webhosting, next to /api,
 * /program and /media (all PHP-owned). In dev, Vite proxies those three to the
 * Docker `web` container (Apache + PHP-FPM, the same tree as production).
 */
const BACKEND = process.env.ARCHE_BACKEND ?? 'http://localhost:8080';

const GIT_COMMIT = (() => {
  try {
    return execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
  } catch {
    return 'dev';
  }
})();

export default defineConfig({
  define: {
    __GIT_COMMIT__: JSON.stringify(GIT_COMMIT),
    __BUILD_TIME__: JSON.stringify(new Date().toISOString()),
  },
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  server: {
    host: true,
    // Not 5173: other projects' dev servers tend to sit there.
    port: 5180,
    strictPort: true,
    proxy: {
      '/api': { target: BACKEND, changeOrigin: false },
      '/program': { target: BACKEND, changeOrigin: false },
      '/media': { target: BACKEND, changeOrigin: false },
    },
  },
  plugins: [
    react(),
    VitePWA({
      // An update never reloads the page under a listener: the banner asks.
      registerType: 'prompt',
      injectRegister: null,
      includeAssets: ['favicon.svg', 'icons/apple-touch-icon.png'],
      manifest: {
        name: 'ARCHE — Christian community radio',
        short_name: 'ARCHE',
        description: 'One program. Many nations. One family.',
        theme_color: '#0a1633',
        background_color: '#070f24',
        display: 'standalone',
        orientation: 'any',
        start_url: '/',
        scope: '/',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2,webp}'],
        // PHP and the generator own these paths; the app shell never answers for them.
        navigateFallbackDenylist: [/^\/api\//, /^\/program\//, /^\/media\//, /^\/cron\.php/, /^\/_arche\//],
        runtimeCaching: [
          {
            // Program structure and the fallback loop, for a flaky connection.
            urlPattern: ({ url }) => url.pathname.startsWith('/program/') && !url.pathname.includes('/slots/') && !url.pathname.endsWith('/live.json'),
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'arche-program',
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 60, maxAgeSeconds: 7 * 86400 },
            },
          },
          {
            // Minute files are immutable; keep the recent ones so a reload
            // during an outage can still find the current window.
            urlPattern: ({ url }) => url.pathname.includes('/slots/'),
            handler: 'CacheFirst',
            options: {
              cacheName: 'arche-slots',
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 40, maxAgeSeconds: 3 * 3600 },
            },
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/media/') && /\.(mp3|webp|jpg)$/.test(url.pathname),
            handler: 'CacheFirst',
            options: {
              cacheName: 'arche-media',
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 120, maxAgeSeconds: 3 * 86400 },
              rangeRequests: true,
            },
          },
        ],
      },
    }),
  ],
  test: {
    passWithNoTests: true,
    projects: [
      {
        extends: true,
        test: { name: 'unit', environment: 'node', include: ['tests/unit/**/*.test.ts'] },
      },
      {
        extends: true,
        test: {
          name: 'component',
          environment: 'jsdom',
          include: ['tests/component/**/*.test.tsx'],
          setupFiles: ['tests/component/setup.ts'],
        },
      },
    ],
  },
});
