import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'

// The SPA is served by Laravel from the same origin under /app, so there is
// no CORS and the Sanctum session cookie just works. In dev, Vite proxies the
// server-side paths to `php artisan serve`.
const laravel = process.env.LARAVEL_URL ?? 'http://localhost:8000'

export default defineConfig({
  base: '/app/',
  define: { __APP_BUILD__: JSON.stringify(process.env.GITHUB_SHA ?? String(Date.now())) },
  plugins: [
    react(),
    VitePWA({
      // Custom worker (src/sw.ts) so push handlers can be added later.
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      // Registration lives in src/pwa/register.ts; the user confirms updates.
      registerType: 'prompt',
      injectRegister: false,
      disable: Boolean(process.env.VITEST),
      injectManifest: {
        // The worker sits at public/app/sw.js (scope /app/), outside static/,
        // so size-limit's per-chunk check does not count it.
        globPatterns: ['index.html', 'static/**/*.{js,css}'],
      },
      manifest: {
        id: '/app/',
        name: 'Eve Industrialist',
        short_name: 'Industrialist',
        description: 'Planning and tracking tools for EVE Online industrialists.',
        start_url: '/app/dashboard',
        scope: '/app/',
        display: 'standalone',
        background_color: '#0d1520',
        theme_color: '#0d1520',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
  // Not the default "assets": public/app/assets would be a real directory at
  // the /app/assets route, and nginx try_files $uri/ answers a refresh with 403.
  build: { outDir: '../public/app', emptyOutDir: true, assetsDir: 'static' },
  server: {
    proxy: {
      '/api': laravel,
      '/sanctum': laravel,
      '/login': laravel,
      '/welcome': laravel,
      '/demo': laravel,
      '/auth': laravel,
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
  },
})
