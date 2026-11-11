import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'


const laravel = process.env.LARAVEL_URL ?? 'http://localhost:8000'

export default defineConfig({
  base: '/app/',
  define: { __APP_BUILD__: JSON.stringify(process.env.GITHUB_SHA ?? String(Date.now())) },
  plugins: [
    react(),
    VitePWA({
      
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      
      registerType: 'prompt',
      injectRegister: false,
      disable: Boolean(process.env.VITEST),
      injectManifest: {
        
        
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
